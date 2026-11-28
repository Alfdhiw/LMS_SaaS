<?php

namespace Tests\Unit;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class M1ArchitectureTest extends TestCase
{
    private const OWNERS = [
        'tenants' => 'Tenancy', 'tenant_domains' => 'Tenancy', 'tenant_settings' => 'Tenancy',
        'users' => 'Identity', 'tenant_memberships' => 'Identity', 'roles' => 'Identity', 'permissions' => 'Identity',
        'membership_roles' => 'Identity', 'role_permissions' => 'Identity', 'account_invitations' => 'Identity',
        'account_recovery_requests' => 'Identity', 'platform_mfa_credentials' => 'Identity',
        'platform_staff' => 'Platform', 'tenant_provisioning_requests' => 'Platform',
        'tenant_audit_logs' => 'Support', 'platform_audit_logs' => 'Support',
        'student_profiles' => 'Academic', 'teacher_profiles' => 'Academic', 'academic_years' => 'Academic',
        'semesters' => 'Academic', 'subjects' => 'Academic', 'classrooms' => 'Academic',
        'classroom_enrollments' => 'Academic', 'courses' => 'Academic',
        'course_teachers' => 'Academic', 'course_enrollments' => 'Academic',
    ];

    public function test_support_has_no_module_dependency_and_module_edges_are_acyclic(): void
    {
        foreach ($this->phpFiles(dirname(__DIR__, 2).'/app') as $path) {
            $source = file_get_contents($path);
            if (str_contains($path, '/Support/')) {
                // Ignore module-internal Support folders: only top-level infrastructure is independent.
                if (str_contains($path, '/app/Support/')) {
                    $this->assertStringNotContainsString('App\\Modules\\', $source, $path);
                }
            }
            if (preg_match('~/Modules/([^/]+)/~', $path, $match) === 1) {
                $this->assertSame([], $this->dependencyViolations($source, $match[1]), $path);
            }
        }
    }

    public function test_module_query_builder_and_model_writes_stay_with_the_table_owner(): void
    {
        $violations = [];
        foreach ($this->phpFiles(dirname(__DIR__, 2).'/app/Modules') as $path) {
            preg_match('~/Modules/([^/]+)/~', $path, $match);
            foreach ($this->writeViolations(file_get_contents($path), $match[1]) as $violation) {
                $violations[] = $path.': '.$violation;
            }
        }
        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_architecture_guard_detects_aliased_cross_owner_writes(): void
    {
        $source = <<<'PHP'
<?php
use Illuminate\Support\Facades\DB as Store;
Store::table('tenants')->where('id', 1)->update(['status' => 'active']);
Store::table('account_invitations')->where('tenant_id', 1)->delete();
PHP;
        $this->assertCount(2, $this->writeViolations($source, 'Platform'));
        $this->assertSame([], $this->writeViolations("<?php use Illuminate\\Support\\Facades\\DB; DB::table('tenants')->where('id', 1)->update(['name' => 'Synthetic']);", 'Tenancy'));
    }

    public function test_academic_owns_all_ten_tables_and_other_modules_cannot_write_them(): void
    {
        foreach ([
            'student_profiles', 'teacher_profiles', 'academic_years', 'semesters', 'subjects',
            'classrooms', 'classroom_enrollments', 'courses', 'course_teachers', 'course_enrollments',
        ] as $table) {
            $source = "<?php use Illuminate\\Support\\Facades\\DB as Store; Store::table('{$table}')->insert(['tenant_id' => 1]);";
            $this->assertSame([], $this->writeViolations($source, 'Academic'), $table);
            foreach (['Identity', 'Tenancy', 'Platform', 'Learning', 'Assessment'] as $consumer) {
                $this->assertCount(1, $this->writeViolations($source, $consumer), $consumer.': '.$table);
            }
        }
    }

    public function test_academic_cannot_write_identity_or_tenancy_and_imports_only_public_ports(): void
    {
        foreach (['users', 'tenant_memberships', 'membership_roles', 'account_invitations', 'tenants', 'tenant_settings'] as $table) {
            $source = "<?php use Illuminate\\Support\\Facades\\DB; DB::table('{$table}')->where('id', 1)->update(['status' => 'active']);";
            $this->assertCount(1, $this->writeViolations($source, 'Academic'), $table);
        }
        $this->assertSame([], $this->dependencyViolations('<?php use App\Modules\Identity\Contracts\MembershipEligibilityReader; use App\Modules\Tenancy\Data\SchoolProfile;', 'Academic'));
        $this->assertNotEmpty($this->dependencyViolations('<?php use App\Modules\Identity\Services\PermissionChecker as Checker;', 'Academic'));
        $this->assertNotEmpty($this->dependencyViolations('<?php use App\Modules\Academic\Contracts\CourseReader;', 'Identity'));
        $this->assertNotEmpty($this->dependencyViolations('<?php use App\Modules\Learning\Contracts\Materials;', 'Academic'));
        $this->assertNotEmpty($this->dependencyViolations('<?php use App\Modules\Academic\Queries\StudentDirectory;', 'Platform'));
        $this->assertSame([], $this->dependencyViolations('<?php use App\Modules\Academic\Contracts\OnboardingSummary;', 'Platform'));
    }

    public function test_guard_detects_grouped_imports_static_model_writes_and_dynamic_sql(): void
    {
        $this->assertNotEmpty($this->dependencyViolations('<?php use App\Modules\Identity\{Contracts\MembershipEligibilityReader, Services\PermissionChecker as Checker};', 'Academic'));
        $this->assertSame([], $this->dependencyViolations('<?php use App\Modules\Identity\{Contracts\MembershipEligibilityReader, Data\MembershipEligibility};', 'Academic'));
        $this->assertNotEmpty($this->writeViolations('<?php use App\Models\User as Account; Account::create([]);', 'Academic'));
        $this->assertSame([], $this->writeViolations('<?php use App\Models\User as Account; Account::create([]);', 'Identity'));
        $this->assertNotEmpty($this->writeViolations('<?php use App\Modules\Academic\Models\StudentProfile; StudentProfile::updateOrCreate([], []);', 'Identity'));
        $this->assertNotEmpty($this->writeViolations('<?php use Illuminate\Support\Facades\DB; DB::select($sql);', 'Academic'));
        $this->assertNotEmpty($this->writeViolations('<?php use Illuminate\Support\Facades\DB; DB::select("DELETE FROM users RETURNING id");', 'Academic'));
        $this->assertNotEmpty($this->writeViolations('<?php use Illuminate\Support\Facades\DB; DB::connection()->statement($sql);', 'Academic'));
        $allowed = '<?php use App\Modules\Tenancy\Contracts\SchoolProfiles; class Workflow { public function __construct(private SchoolProfiles $profiles) {} public function go($context, $changes) { $this->profiles->update($context, $changes); } }';
        $this->assertSame([], $this->writeViolations($allowed, 'Identity'));
        $this->assertNotEmpty($this->writeViolations(str_replace('SchoolProfiles $profiles', 'object $profiles', $allowed), 'Identity'));
    }

    private function dependencyViolations(string $source, string $module): array
    {
        $forbidden = [
            'Tenancy' => ['Identity', 'Platform', 'Academic', 'Learning', 'Assessment'],
            'Identity' => ['Platform', 'Academic', 'Learning', 'Assessment'],
            'Academic' => ['Platform', 'Learning', 'Assessment'],
            'Learning' => ['Platform', 'Assessment'],
            'Assessment' => ['Platform'],
        ];
        $violations = [];
        $nodes = (new ParserFactory)->createForHostVersion()->parse($source);
        foreach ((new NodeFinder)->findInstanceOf($nodes, GroupUse::class) as $group) {
            foreach ($group->uses as $use) {
                $source .= "\n".$group->prefix->toString().'\\'.$use->name->toString();
            }
        }
        preg_match_all('/App\\\\Modules\\\\([A-Za-z]+)\\\\([A-Za-z]+)/', $source, $references, PREG_SET_ORDER);
        foreach ($references as $reference) {
            [$fullName, $target, $layer] = $reference;
            if (in_array($target, $forbidden[$module] ?? [], true)
                || ($module === 'Academic' && in_array($target, ['Identity', 'Tenancy'], true)
                    && ! in_array($layer, ['Contracts', 'Data'], true))
                || ($module === 'Platform' && $target === 'Academic' && ! in_array($layer, ['Contracts', 'Data'], true))) {
                $violations[] = $module.' cannot import '.$fullName;
            }
        }

        return $violations;
    }

    private function writeViolations(string $source, string $module): array
    {
        $nodes = (new ParserFactory)->createForHostVersion()->parse($source);
        $traverser = new NodeTraverser(new NameResolver);
        $nodes = $traverser->traverse($nodes);
        $finder = new NodeFinder;
        $violations = [];
        $contractCalls = [];
        if ($module === 'Identity') {
            foreach ($finder->findInstanceOf($nodes, Class_::class) as $class) {
                $properties = [];
                foreach ($class->getMethod('__construct')?->params ?? [] as $parameter) {
                    if ($parameter->flags !== 0 && $parameter->type instanceof Name
                        && $parameter->type->toString() === 'App\\Modules\\Tenancy\\Contracts\\SchoolProfiles'
                        && is_string($parameter->var->name)) {
                        $properties[] = $parameter->var->name;
                    }
                }
                foreach ($finder->findInstanceOf($class, MethodCall::class) as $candidate) {
                    $receiver = $candidate->var;
                    if ($candidate->name instanceof Node\Identifier && $candidate->name->toString() === 'update'
                        && $receiver instanceof PropertyFetch && $receiver->var instanceof Variable
                        && $receiver->var->name === 'this' && $receiver->name instanceof Node\Identifier
                        && in_array($receiver->name->toString(), $properties, true)) {
                        $contractCalls[spl_object_id($candidate)] = true;
                    }
                }
            }
        }
        foreach ($finder->findInstanceOf($nodes, MethodCall::class) as $call) {
            if (isset($contractCalls[spl_object_id($call)])) {
                continue;
            }
            if ($call->name instanceof Node\Identifier && in_array($call->name->toString(), ['statement', 'unprepared', 'select', 'selectOne', 'affectingStatement'], true)
                && $call->var instanceof StaticCall && $call->var->class instanceof Name
                && $call->var->class->toString() === 'Illuminate\\Support\\Facades\\DB'
                && $call->var->name instanceof Node\Identifier && $call->var->name->toString() === 'connection') {
                $violations[] = 'Raw connection SQL bypasses the table ownership guard at line '.$call->getStartLine();
            }
            if (! $call->name instanceof Node\Identifier || ! in_array($call->name->toString(), [
                'insert', 'insertGetId', 'insertOrIgnore', 'update', 'updateOrInsert', 'upsert', 'delete',
                'increment', 'decrement', 'truncate', 'save', 'forceDelete',
            ], true)) {
                continue;
            }
            $root = $call->var;
            while ($root instanceof MethodCall) {
                $root = $root->var;
            }
            $table = null;
            if ($root instanceof StaticCall && $root->class instanceof Name) {
                if ($root->class->toString() === 'Illuminate\\Support\\Facades\\DB' && $root->name->toString() === 'table'
                    && ($root->args[0]->value ?? null) instanceof String_) {
                    $table = explode(' ', $root->args[0]->value->value)[0];
                } elseif ($root->class->toString() === 'App\\Models\\User') {
                    $table = 'users';
                }
            }
            if ($table === null || (self::OWNERS[$table] ?? null) !== $module) {
                $violations[] = $module.' cannot directly write '.($table ?? 'an unclassified target').' at line '.$call->getStartLine();
            }
        }
        foreach ($finder->findInstanceOf($nodes, StaticCall::class) as $call) {
            if ($call->class instanceof Name && $call->class->toString() === 'Illuminate\\Support\\Facades\\DB'
                && $call->name instanceof Node\Identifier && in_array($call->name->toString(), ['statement', 'unprepared', 'insert', 'update', 'delete'], true)) {
                $violations[] = 'Raw mutation bypasses the table ownership guard at line '.$call->getStartLine();
            }
            if ($call->class instanceof Name && $call->class->toString() === 'Illuminate\\Support\\Facades\\DB'
                && $call->name instanceof Node\Identifier && in_array($call->name->toString(), ['select', 'selectOne', 'selectFromWriteConnection'], true)) {
                $sql = $call->args[0]->value ?? null;
                if (! $sql instanceof String_ || preg_match('/\A\s*select\b/i', $sql->value) !== 1
                    || preg_match('/\b(insert|update|delete|merge|alter|drop|create|truncate)\b/i', $sql->value) === 1
                    || str_contains($sql->value, ';')) {
                    $violations[] = 'Dynamic or mutating raw SQL bypasses the table ownership guard at line '.$call->getStartLine();
                }
            }
            if ($call->class instanceof Name && $call->name instanceof Node\Identifier
                && in_array($call->name->toString(), ['create', 'forceCreate', 'insert', 'insertOrIgnore', 'upsert', 'update', 'delete', 'updateOrCreate', 'firstOrCreate', 'destroy', 'truncate', 'increment', 'decrement'], true)
                && str_starts_with($call->class->toString(), 'App\\')) {
                $table = $call->class->toString() === 'App\\Models\\User' ? 'users' : null;
                if ($table === null || (self::OWNERS[$table] ?? null) !== $module) {
                    $violations[] = $module.' cannot directly write '.($table ?? 'an unclassified model').' at line '.$call->getStartLine();
                }
            }
        }

        return $violations;
    }

    private function phpFiles(string $directory): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        return $files;
    }
}

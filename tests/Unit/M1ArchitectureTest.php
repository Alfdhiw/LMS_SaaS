<?php

namespace Tests\Unit;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
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
            if (str_contains($path, '/Modules/Tenancy/')) {
                $this->assertStringNotContainsString('App\\Modules\\Identity\\', $source, $path);
                $this->assertStringNotContainsString('App\\Modules\\Platform\\', $source, $path);
            }
            if (str_contains($path, '/Modules/Identity/')) {
                $this->assertStringNotContainsString('App\\Modules\\Platform\\', $source, $path);
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

    private function writeViolations(string $source, string $module): array
    {
        $nodes = (new ParserFactory)->createForHostVersion()->parse($source);
        $traverser = new NodeTraverser(new NameResolver);
        $nodes = $traverser->traverse($nodes);
        $finder = new NodeFinder;
        $violations = [];
        foreach ($finder->findInstanceOf($nodes, MethodCall::class) as $call) {
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

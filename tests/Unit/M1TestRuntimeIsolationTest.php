<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class M1TestRuntimeIsolationTest extends TestCase
{
    private ?string $probeDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The test runtime launcher runs inside the Linux PHP container.');
        }
        $this->probeDirectory = sys_get_temp_dir().'/m1-runtime-probe-'.bin2hex(random_bytes(8));
        mkdir($this->probeDirectory.'/bin', 0700, true);
        file_put_contents($this->probeDirectory.'/bin/php', <<<'SH'
#!/bin/sh
exec "$M1_RUNTIME_PROBE_PHP" "$M1_RUNTIME_PROBE_SCRIPT" "$@"
SH);
        chmod($this->probeDirectory.'/bin/php', 0700);
        file_put_contents($this->probeDirectory.'/probe.php', <<<'PHP'
<?php
$storage = getenv('LARAVEL_STORAGE_PATH');
$root = dirname($storage);
$paths = ['VIEW_COMPILED_PATH', 'APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_EVENTS_CACHE', 'APP_ROUTES_CACHE'];
foreach ($paths as $name) {
    if (!str_starts_with(getenv($name), $root.'/')) { exit(80); }
}
foreach (['framework/cache/data', 'framework/cache/locks', 'framework/sessions', 'framework/views', 'logs', 'app/private', 'app/public'] as $path) {
    if (!is_dir($storage.'/'.$path)) { exit(81); }
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'lms_saas_test', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'] as $name => $expected) {
    if (getenv($name) !== $expected) { exit(82); }
}
$log = $storage.'/logs/laravel.log';
file_put_contents($log, 'Synthetic runtime probe');
file_put_contents(getenv('VIEW_COMPILED_PATH').'/probe.php', '<?php /* synthetic view */');
file_put_contents(getenv('APP_PACKAGES_CACHE'), '<?php return [];');
if ((fileperms($root) & 0077) !== 0 || (fileperms($log) & 0077) !== 0) { exit(83); }
// Verify Laravel itself consumes the exported paths, not just the shell environment.
require getenv('M1_RUNTIME_PROBE_PROJECT').'/vendor/autoload.php';
$app = require getenv('M1_RUNTIME_PROBE_PROJECT').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($app->storagePath() !== $storage || config('view.compiled') !== getenv('VIEW_COMPILED_PATH')
    || config('logging.channels.single.path') !== $log) { exit(84); }
foreach (['getCachedConfigPath' => 'APP_CONFIG_CACHE', 'getCachedServicesPath' => 'APP_SERVICES_CACHE', 'getCachedPackagesPath' => 'APP_PACKAGES_CACHE', 'getCachedEventsPath' => 'APP_EVENTS_CACHE', 'getCachedRoutesPath' => 'APP_ROUTES_CACHE'] as $method => $variable) {
    if ($app->$method() !== getenv($variable)) { exit(85); }
}
$compiler = $app->make('blade.compiler');
$source = resource_path('views/platform/layout.blade.php');
$compiler->compile($source);
if (!is_readable($compiler->getCompiledPath($source)) || !str_starts_with($compiler->getCompiledPath($source), $root.'/')) { exit(86); }
echo json_encode(['root' => $root, 'args' => array_slice($argv, 1), 'mask' => umask()], JSON_THROW_ON_ERROR);
exit((int) getenv('M1_RUNTIME_PROBE_EXIT'));
PHP);
    }

    protected function tearDown(): void
    {
        if ($this->probeDirectory !== null) {
            $resolved = realpath($this->probeDirectory);
            if ($resolved !== false && dirname($resolved) === realpath(sys_get_temp_dir())
                && str_starts_with(basename($resolved), 'm1-runtime-probe-') && ! is_link($this->probeDirectory)) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        }
        parent::tearDown();
    }

    public function test_success_isolates_storage_and_bootstrap_cache_without_relaxing_private_umask(): void
    {
        $first = $this->probe(0, ['--filter', 'M1(HostContext|AuthRbac)Test']);
        $first->mustRun();
        $payload = json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['artisan', 'test', '--filter', 'M1(HostContext|AuthRbac)Test'], $payload['args']);
        $this->assertSame(0077, $payload['mask']);
        $this->assertStringStartsWith('/tmp/lms-saas-test-runtime.', $payload['root']);
        $this->assertDirectoryDoesNotExist($payload['root']);

        $second = $this->probe(0);
        $second->mustRun();
        $secondPayload = json_decode($second->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame($payload['root'], $secondPayload['root']);
        $this->assertSame(['artisan', 'test'], $secondPayload['args']);
        $this->assertDirectoryDoesNotExist($secondPayload['root']);
    }

    public function test_failure_preserves_exit_code_and_cleans_only_its_own_runtime_directory(): void
    {
        file_put_contents($this->probeDirectory.'/keep.txt', 'Synthetic caller-owned file');
        $process = $this->probe(23);
        $process->run();
        $this->assertSame(23, $process->getExitCode(), $process->getErrorOutput());
        $payload = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertDirectoryDoesNotExist($payload['root']);
        $this->assertSame('Synthetic caller-owned file', file_get_contents($this->probeDirectory.'/keep.txt'));
    }

    public function test_launcher_rejects_arbitrary_artisan_options(): void
    {
        $process = $this->probe(0, ['migrate', '--force']);
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertStringContainsString('Only an optional --filter value is allowed.', $process->getErrorOutput());
    }

    public function test_supported_test_entrypoints_delegate_to_the_isolated_launcher(): void
    {
        foreach (['scripts/m1-owner-exec.sh', 'scripts/setup.ps1', 'scripts/setup.sh', 'scripts/verify.ps1', 'scripts/verify.sh', '.github/workflows/m0-ci.yml'] as $path) {
            $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
            $this->assertStringContainsString('scripts/m1-test-runtime.sh', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/\bphp artisan test\b/', $source, $path);
        }
    }

    private function probe(int $exitCode, array $arguments = []): Process
    {
        return new Process(['sh', 'scripts/m1-test-runtime.sh', ...$arguments], dirname(__DIR__, 2), [
            'PATH' => $this->probeDirectory.'/bin:'.getenv('PATH'),
            'M1_RUNTIME_PROBE_PHP' => PHP_BINARY,
            'M1_RUNTIME_PROBE_SCRIPT' => $this->probeDirectory.'/probe.php',
            'M1_RUNTIME_PROBE_PROJECT' => dirname(__DIR__, 2),
            'M1_RUNTIME_PROBE_EXIT' => (string) $exitCode,
            // Ambient native environment must not redirect tests into the application runtime.
            'APP_ENV' => 'local', 'DB_DATABASE' => 'lms_saas', 'DB_URL' => 'synthetic-invalid-url',
            'LARAVEL_STORAGE_PATH' => $this->probeDirectory.'/caller-storage',
            'VIEW_COMPILED_PATH' => $this->probeDirectory.'/caller-views',
            'APP_CONFIG_CACHE' => $this->probeDirectory.'/caller-config.php',
        ], null, 15);
    }
}

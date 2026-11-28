<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\M2FoundationFixtures;
use Tests\TestCase;

final class M2AcademicFoundationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use M2FoundationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => self::DRAFT_PATH, '--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        try {
            // Remove only the pending M2 batch before the M1 trait rolls back its batch.
            $this->artisan('migrate:rollback', ['--path' => self::DRAFT_PATH, '--step' => 1, '--force' => true])->assertExitCode(0);
        } finally {
            parent::tearDown();
        }
    }

    public function test_concurrent_active_year_inserts_have_exactly_one_winner(): void
    {
        $tenant = $this->foundationTenant('m2-race-year');
        $this->race($tenant, null, 'm2_year_one_active');
        $this->assertSame(1, DB::table('academic_years')->where('tenant_id', $tenant)->where('status', 'active')->count());
    }

    public function test_concurrent_active_semester_inserts_have_exactly_one_winner(): void
    {
        $tenant = $this->foundationTenant('m2-race-semester');
        $year = $this->foundationYear($tenant, ['status' => 'active']);
        $this->race($tenant, $year, 'm2_semester_one_active');
        $this->assertSame(1, DB::table('semesters')->where('tenant_id', $tenant)->where('status', 'active')->count());
    }

    private function race(int $tenant, ?int $year, string $constraint): void
    {
        $database = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_DATABASE' => 'lms_saas_test',
            'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
            'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'],
        ];
        $tag = 'm2_pr1_unique_'.Str::lower(Str::random(8));
        $workers = [];
        $reachedContention = false;
        DB::beginTransaction();
        try {
            // Both independent connections must block on the real unique index,
            // not merely start at approximately the same time.
            if ($year === null) {
                $this->foundationYear($tenant, ['status' => 'active']);
            } else {
                $this->foundationSemester($tenant, $year, ['status' => 'active']);
            }
            foreach (['odd', 'even'] as $code) {
                $payload = ['tenant' => $tenant, 'year' => $year, 'code' => $code, 'tag' => $tag, 'constraint' => $constraint];
                $worker = new Process([PHP_BINARY, 'tests/Support/m2_foundation_unique_worker.php'], base_path(), $environment, json_encode($payload, JSON_THROW_ON_ERROR), 40);
                $worker->start();
                $workers[] = $worker;
            }
            $blocked = 0;
            for ($attempt = 0; $attempt < 150; $attempt++) {
                DB::statement('select pg_stat_clear_snapshot()');
                $blocked = DB::table('pg_stat_activity')->where('application_name', $tag)
                    ->where('datname', 'lms_saas_test')->where('wait_event_type', 'Lock')->count();
                if ($blocked === 2 || ! $workers[0]->isRunning() || ! $workers[1]->isRunning()) {
                    break;
                }
                usleep(100000);
            }
            $this->assertSame(2, $blocked, 'Both workers must contend on the unique index.');
            $reachedContention = true;
        } finally {
            DB::rollBack();
            if (! $reachedContention) {
                foreach ($workers as $worker) {
                    $worker->stop();
                }
            }
        }
        $outcomes = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $outcomes[] = trim($worker->getOutput());
        }
        sort($outcomes);
        $this->assertSame(['inserted', 'rejected:'.$constraint], $outcomes);
    }
}

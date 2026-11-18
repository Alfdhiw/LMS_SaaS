<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class M1ProvisioningConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_processes_with_same_key_create_one_tenant_and_replay(): void
    {
        Artisan::call('db:seed', [
            '--class' => RolePermissionSeeder::class,
            '--no-interaction' => true,
        ]);

        $actorId = $this->createPlatformActor();
        $slug = 'sekolah-concurrency-'.Str::lower(Str::random(6));
        $key = 'concurrency-key-'.Str::lower(Str::random(12));
        $processes = [
            $this->probe($actorId, $slug, $key),
            $this->probe($actorId, $slug, $key),
        ];

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(
                0,
                $process->getExitCode(),
                'stdout: '.$process->getOutput().' stderr: '.$process->getErrorOutput(),
            );
        }

        $payloads = array_map(
            static fn (Process $process): array => json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR),
            $processes,
        );

        $this->assertCount(1, DB::table('tenants')->where('slug', $slug)->get());
        $this->assertCount(1, DB::table('tenant_provisioning_requests')->where('idempotency_key_hash', hash('sha256', $key))->get());
        $this->assertSame(['ok', 'ok'], array_values(array_column($payloads, 'status')));
        $replayStates = array_column($payloads, 'idempotent_replay');
        $this->assertContains(false, $replayStates);
        $this->assertContains(true, $replayStates);
    }

    private function createPlatformActor(): int
    {
        $email = 'concurrency-'.Str::lower(Str::random(8)).'@example.test';
        $userId = (int) DB::table('users')->insertGetId([
            'public_ulid' => (string) Str::ulid(),
            'name' => 'Concurrency Operator',
            'email' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make('Platform!234'),
            'status' => 'active',
            'auth_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('platform_staff')->insert([
            'user_id' => $userId,
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        return $userId;
    }

    private function probe(int $actorId, string $slug, string $key): Process
    {
        $database = config('database.connections.pgsql');

        return new Process([
            PHP_BINARY,
            'artisan',
            'm1:provision-concurrency',
            (string) $actorId,
            $slug,
            $key,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'true',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) ($database['host'] ?? 'db'),
            'DB_PORT' => (string) ($database['port'] ?? 5432),
            'DB_DATABASE' => (string) ($database['database'] ?? 'lms_saas_test'),
            'DB_USERNAME' => (string) ($database['username'] ?? 'lms_user'),
            'DB_PASSWORD' => (string) ($database['password'] ?? ''),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ], null, 30);
    }
}

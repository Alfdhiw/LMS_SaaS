<?php

use App\Models\User;
use App\Modules\Platform\Data\ProvisionTenantData;
use App\Modules\Platform\Services\PlatformOperationException;
use App\Modules\Platform\Services\TenantProvisioningService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('m1:provision-concurrency {actorId} {slug} {idempotencyKey}', function (): int {
    if (! app()->environment('testing') || (DB::selectOne('select current_database() as name')->name ?? null) !== 'lms_saas_test') {
        $this->error('Perintah probe hanya boleh dijalankan pada database test khusus.');

        return 1;
    }

    $actor = User::query()->find((int) $this->argument('actorId'));

    if ($actor === null) {
        $this->error('Aktor probe tidak ditemukan.');

        return 1;
    }

    $request = Request::create('http://admin.platform.test/tenants', 'POST');
    $request->headers->set('Idempotency-Key', (string) $this->argument('idempotencyKey'));
    $request->attributes->set('request_id', (string) str()->uuid());
    $data = new ProvisionTenantData(
        schoolName: 'Sekolah Concurrency '.(string) $this->argument('slug'),
        slug: (string) $this->argument('slug'),
        timezone: 'Asia/Jakarta',
        adminName: 'Admin Concurrency',
        adminEmail: 'admin-'.(string) $this->argument('slug').'@example.test',
        adminUsername: 'admin-'.(string) $this->argument('slug'),
    );

    try {
        $result = app(TenantProvisioningService::class)
            ->provision($request, $actor, $data);
    } catch (PlatformOperationException $exception) {
        $this->line(json_encode([
            'status' => 'error',
            'failure_code' => $exception->failureCode,
        ], JSON_THROW_ON_ERROR));

        return 1;
    }

    $this->line(json_encode([
        'status' => 'ok',
        'tenant_public_ulid' => $result->tenantPublicUlid,
        'idempotent_replay' => $result->replayed,
    ], JSON_THROW_ON_ERROR));

    return 0;
})->purpose('Probe concurrent idempotent tenant provisioning with synthetic data');

Artisan::command('m1:smoke-hosts {--limit=3}', function (): int {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('Perintah smoke host hanya boleh dijalankan di local/testing.');

        return 1;
    }

    $hosts = DB::table('tenant_domains')
        ->join('tenants', 'tenants.id', '=', 'tenant_domains.tenant_id')
        ->where('tenants.status', 'active')
        ->where('tenant_domains.status', 'active')
        ->where('tenant_domains.is_primary', true)
        ->orderBy('tenant_domains.tenant_id')
        ->limit((int) $this->option('limit'))
        ->pluck('tenant_domains.hostname');

    foreach ($hosts as $host) {
        $this->line((string) $host);
    }

    return 0;
})->purpose('List synthetic active tenant hosts for the local host smoke script');

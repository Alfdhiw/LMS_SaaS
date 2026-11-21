<?php

use App\Models\User;
use App\Modules\Identity\Services\BootstrapAdminActivationService;
use App\Modules\Identity\Services\ExistingAccountInvitationService;
use App\Modules\Identity\Services\SchoolAssistedRecoveryService;
use App\Modules\Identity\Services\SelfEmailRecoveryService;
use App\Modules\Platform\Services\PlatformOperationException;
use App\Modules\Platform\Services\TenantLifecycleService;
use App\Modules\Tenancy\Services\TenantResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// This worker is only launched by the PostgreSQL integration test with synthetic data.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'lms_saas_test') {
    fwrite(STDERR, 'This worker requires the dedicated test database; environment='.getenv('APP_ENV').', database='.getenv('DB_DATABASE'));
    exit(2);
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ((DB::selectOne('select current_database() as name')->name ?? null) !== 'lms_saas_test') {
    fwrite(STDERR, 'Refusing worker connection outside the dedicated test database.');
    exit(2);
}

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
DB::statement("SET application_name = 'm1_pr5_identity_race'");
$resolver = $app->make(TenantResolver::class);
$context = $input['action'] === 'bootstrap-activate'
    ? $resolver->resolveForActivation($input['host'])
    : $resolver->resolve($input['host']);
if ($context === null) {
    exit(3);
}
$request = Request::create('http://'.$input['host'].'/', 'POST');

try {
    if ($input['action'] === 'recovery') {
        $app->make(SelfEmailRecoveryService::class)
            ->consume($request, $context, $input['id'], $input['token'], 'ChangedPassword!234');
    } elseif ($input['action'] === 'school-recovery') {
        $app->make(SchoolAssistedRecoveryService::class)
            ->consume($request, $context, $input['id'], $input['token'], 'ChangedPassword!234');
    } elseif ($input['action'] === 'school-approve') {
        $app->make(SchoolAssistedRecoveryService::class)
            ->approve($request, $context, User::query()->findOrFail($input['actor']), $input['membership'], $input['id']);
    } elseif ($input['action'] === 'join') {
        $app->make(ExistingAccountInvitationService::class)
            ->accept($request, $context, $input['id'], $input['token'], $input['email'], 'ValidPassword!234');
    } elseif ($input['action'] === 'suspend') {
        $app->make(TenantLifecycleService::class)
            ->transition($request, User::query()->findOrFail($input['actor']), $input['id'], 'suspend', 'active', 'concurrency_test');
    } elseif ($input['action'] === 'bootstrap-activate') {
        $activated = $app->make(BootstrapAdminActivationService::class)
            ->activate($request, $context, $input['id'], $input['token'], 'ChangedPassword!234');
        if (! $activated) {
            echo 'rejected:404';
            exit(0);
        }
    } else {
        exit(4);
    }
    echo 'consumed';
} catch (HttpExceptionInterface $exception) {
    echo 'rejected:'.$exception->getStatusCode();
} catch (PlatformOperationException $exception) {
    echo 'rejected:'.$exception->statusCode;
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception));
    exit(1);
}

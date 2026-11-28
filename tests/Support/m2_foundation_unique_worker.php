<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Synthetic integration worker, never an application entry point.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'lms_saas_test' || getenv('DB_URL')) {
    fwrite(STDERR, 'M2 worker requires the isolated test target.');
    exit(2);
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'pgsql'
    || (DB::selectOne('select current_database() as name')->name ?? null) !== 'lms_saas_test') {
    fwrite(STDERR, 'M2 worker target verification failed.');
    exit(2);
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (! is_int($input['tenant'] ?? null) || $input['tenant'] < 1
    || (! is_null($input['year'] ?? null) && (! is_int($input['year']) || $input['year'] < 1))
    || ! in_array($input['code'] ?? null, ['odd', 'even'], true)
    || preg_match('/\Am2_pr1_unique_[a-z0-9]{8}\z/', $input['tag'] ?? '') !== 1
    || ($input['constraint'] ?? null) !== ($input['year'] === null ? 'm2_year_one_active' : 'm2_semester_one_active')) {
    fwrite(STDERR, 'Invalid synthetic worker payload.');
    exit(2);
}
DB::select('select set_config(?, ?, false)', ['application_name', $input['tag']]);
DB::statement("SET lock_timeout = '20s'");
DB::statement("SET statement_timeout = '25s'");
try {
    $record = ['public_ulid' => (string) Str::ulid(), 'tenant_id' => $input['tenant'], 'status' => 'active',
        'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31'];
    if ($input['year'] === null) {
        DB::table('academic_years')->insert($record + ['name' => 'Sintetis '.$input['code']]);
    } else {
        DB::table('semesters')->insert($record + ['academic_year_id' => $input['year'], 'code' => $input['code']]);
    }
    echo 'inserted';
} catch (QueryException $exception) {
    if (($exception->errorInfo[0] ?? null) !== '23505' || ! str_contains($exception->getMessage(), $input['constraint'])) {
        fwrite(STDERR, 'Unexpected synthetic database outcome.');
        exit(1);
    }
    echo 'rejected:'.$input['constraint'];
}

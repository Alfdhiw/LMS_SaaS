<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class M1TestDatabaseSafetyTest extends TestCase
{
    public function test_application_tests_use_only_the_dedicated_postgresql_database(): void
    {
        $this->assertSame('lms_saas_test', DB::selectOne('select current_database() as name')->name);
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('array', config('mail.default'));
    }
}

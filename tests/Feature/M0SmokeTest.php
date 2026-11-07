<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class M0SmokeTest extends TestCase
{
    public function test_M0_home_page_is_available(): void
    {
        $this->get('/')->assertOk()->assertSee('LMS SaaS Multi-Sekolah');
    }

    public function test_M0_health_endpoint_returns_ok(): void
    {
        $this->get('/health')->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_M0_postgresql_and_redis_are_reachable(): void
    {
        $result = DB::selectOne('SELECT 1 AS ready');
        $this->assertSame(1, (int) $result->ready);
        $this->assertNotFalse(Redis::connection()->ping());
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Override the container process environment before Laravel bootstraps.
        // phpunit.xml alone does not override all native getenv() values.
        foreach ([
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'lms_saas_test',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        ] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        putenv('DB_URL=');
        $_ENV['DB_URL'] = '';
        $_SERVER['DB_URL'] = '';
        $app = parent::createApplication();

        $app->instance('env', 'testing');
        $app['config']->set('app.env', 'testing');

        // Docker's process environment can override phpunit.xml. Set the target
        // before RefreshDatabase / DatabaseMigrations run and verify it on the server.
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.url', null);
        $app['config']->set('database.connections.pgsql.database', 'lms_saas_test');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.limiter', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('mail.default', 'array');
        $app['db']->purge('pgsql');

        $database = $app['db']->connection('pgsql')->selectOne('select current_database() as name');
        if (($database->name ?? null) !== 'lms_saas_test') {
            throw new \RuntimeException('Refusing to run tests outside the dedicated lms_saas_test database.');
        }

        return $app;
    }
}

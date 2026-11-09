<?php

/**
 * Local M1 host configuration. Production values must be supplied via environment.
 * Hostname resolution must NEVER accept an arbitrary tenant ID from the client.
 */
return [
    'central_host' => env('SAAS_CENTRAL_HOST', 'admin.platform.test'),
    'tenant_base_domain' => env('SAAS_TENANT_BASE_DOMAIN', 'platform.test'),
    'allow_local_http' => env('APP_ENV') === 'local',
];

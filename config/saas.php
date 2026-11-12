<?php

/**
 * Local M1 host configuration. Production values must be supplied via environment.
 * Hostname resolution must NEVER accept an arbitrary tenant ID from the client.
 */
return [
    'central_host' => env('SAAS_CENTRAL_HOST', 'admin.platform.test'),
    'tenant_base_domain' => env('SAAS_TENANT_BASE_DOMAIN', 'platform.test'),
    'allow_local_http' => env('APP_ENV') === 'local',
    'central_mfa_required_in_production' => true,
    'central_mfa_required' => env('SAAS_CENTRAL_MFA_REQUIRED', false),
    'platform_role_permissions' => [
        'super_admin' => [
            'platform.tenants.view',
            'platform.tenants.create',
            'platform.tenants.update',
            'platform.tenants.transition',
            'platform.plans.assign',
            'platform.audit.view',
            'platform.staff.mfa_reset',
        ],
    ],
];

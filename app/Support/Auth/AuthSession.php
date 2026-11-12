<?php

namespace App\Support\Auth;

final class AuthSession
{
    public const AREA = 'auth.area';

    public const HOST = 'auth.host';

    public const USER_ID = 'auth.user_id';

    public const TENANT_ID = 'auth.tenant_id';

    public const MEMBERSHIP_ID = 'auth.membership_id';

    public const USER_AUTH_VERSION = 'auth.user_auth_version';

    public const MEMBERSHIP_AUTH_VERSION = 'auth.membership_auth_version';

    public const MFA_VERIFIED = 'auth.mfa_verified';

    public const MFA_PENDING_USER_ID = 'auth.mfa_pending_user_id';

    public const MFA_PENDING_AUTH_VERSION = 'auth.mfa_pending_auth_version';

    public const MFA_PENDING_HOST = 'auth.mfa_pending_host';

    public const MFA_PENDING_AT = 'auth.mfa_pending_at';

    public const MFA_SETUP_SECRET = 'auth.mfa_setup_secret';
}

<?php

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Data\BootstrapInvitation;

interface BootstrapAdminAccounts
{
    /** Called within the provisioning transaction after locking/creating the pending tenant. */
    public function create(int $tenantId, int $actorId, string $name, string $email, string $username): BootstrapInvitation;

    /** Called with the pending tenant locked; rotates only a pending bootstrap invitation in that tenant. */
    public function rotate(int $tenantId, string $invitationUlid): BootstrapInvitation;
}

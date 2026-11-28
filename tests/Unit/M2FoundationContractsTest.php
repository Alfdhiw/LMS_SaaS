<?php

namespace Tests\Unit;

use App\Modules\Identity\Contracts\MembershipEligibilityReader;
use App\Modules\Identity\Data\MembershipEligibility;
use App\Modules\Identity\Data\MembershipSelection;
use App\Modules\Tenancy\Contracts\SchoolProfiles;
use App\Modules\Tenancy\Data\SchoolProfile;
use App\Modules\Tenancy\Data\SchoolProfileUpdate;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class M2FoundationContractsTest extends TestCase
{
    public function test_membership_selection_is_bounded_canonical_and_immutable(): void
    {
        $this->assertSame([1, 3, 9], (new MembershipSelection([9, 1, 3, 9]))->membershipIds);
        $this->assertSame([], (new MembershipSelection([]))->membershipIds);
        $this->assertCount(100, (new MembershipSelection(range(1, 100)))->membershipIds);
        $selection = new MembershipSelection([1]);
        $this->expectException(\Error::class);
        $selection->membershipIds[] = 2;
    }

    #[DataProvider('invalidSelections')]
    public function test_membership_selection_rejects_unsafe_input(array $ids): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MembershipSelection($ids);
    }

    public static function invalidSelections(): array
    {
        return ['zero' => [[0]], 'negative' => [[-1]], 'string' => [['1']],
            'boolean' => [[true]], 'associative' => [['membership' => 1]], 'oversized' => [range(1, 101)]];
    }

    public function test_eligibility_facts_do_not_make_invited_or_revoked_members_active(): void
    {
        foreach (['invited', 'suspended', 'left'] as $status) {
            $facts = new MembershipEligibility(1, 2, 3, 'active', $status, 4, 5, ['teacher']);
            $this->assertFalse($facts->isActive());
            $this->assertTrue($facts->hasRole('teacher'));
        }
        foreach (['pending', 'suspended'] as $status) {
            $this->assertFalse((new MembershipEligibility(1, 2, 3, $status, 'active', 4, 5, ['student']))->isActive());
        }
        $active = new MembershipEligibility(1, 2, 3, 'active', 'active', 4, 5, ['teacher', 'student', 'teacher']);
        $this->assertTrue($active->isActive());
        $this->assertSame(['student', 'teacher'], $active->roleKeys);
        $this->assertFalse($active->hasRole('school_admin'));
        $this->assertTrue($active->matchesVersions(4, 5));
        $this->assertFalse($active->matchesVersions(5, 5));
        $this->assertFalse($active->matchesVersions(4, 6));
    }

    public function test_platform_roles_are_not_tenant_eligibility(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MembershipEligibility(1, 2, 3, 'active', 'active', 1, 1, ['super_admin']);
    }

    public function test_eligibility_snapshot_has_only_minimum_identity_facts(): void
    {
        $facts = new MembershipEligibility(1, 2, 3, 'active', 'active', 1, 1, []);
        $keys = array_keys(get_object_vars($facts));
        sort($keys);
        $this->assertSame(['membershipAuthVersion', 'membershipId', 'membershipStatus', 'roleKeys', 'tenantId', 'userAuthVersion', 'userId', 'userStatus'], $keys);
        $this->assertTrue((new ReflectionClass($facts))->isReadOnly());
    }

    public function test_school_profile_update_preserves_valid_values_and_explicit_null_color(): void
    {
        $changes = SchoolProfileUpdate::fromArray(['name' => ' Sekolah Sintetis ', 'timezone' => 'Asia/Jakarta', 'accent_color' => '#1A2b3C']);
        $this->assertSame('Sekolah Sintetis', $changes->name);
        $this->assertSame('Asia/Jakarta', $changes->timezone);
        $this->assertSame('#1A2b3C', $changes->accentColor);
        $this->assertNull((new SchoolProfileUpdate('Sekolah Sintetis', 'UTC', null))->accentColor);
        $this->assertTrue((new ReflectionClass($changes))->isReadOnly());
    }

    #[DataProvider('invalidProfileChanges')]
    public function test_school_profile_update_rejects_mass_assignment_and_invalid_fields(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        SchoolProfileUpdate::fromArray($input);
    }

    public static function invalidProfileChanges(): array
    {
        $valid = ['name' => 'Sekolah Sintetis', 'timezone' => 'Asia/Jakarta', 'accent_color' => null];
        $cases = [];
        foreach (['tenant_id', 'slug', 'hostname', 'status', 'plan', 'onboarding_json', 'logo_object_key'] as $field) {
            $cases[$field] = [$valid + [$field => 'forbidden']];
        }
        $cases['blank_name'] = [array_replace($valid, ['name' => '  '])];
        $cases['long_name'] = [array_replace($valid, ['name' => str_repeat('x', 201)])];
        $cases['timezone'] = [array_replace($valid, ['timezone' => '+07:00'])];
        $cases['color'] = [array_replace($valid, ['accent_color' => '#123'])];
        $cases['color_type'] = [array_replace($valid, ['accent_color' => 123])];
        $cases['name_type'] = [array_replace($valid, ['name' => ['not-a-string']])];
        $cases['missing_color'] = [['name' => 'Sekolah Sintetis', 'timezone' => 'Asia/Jakarta']];

        return $cases;
    }

    public function test_public_contracts_use_context_and_dtos_not_foreign_models(): void
    {
        $reader = new ReflectionMethod(MembershipEligibilityReader::class, 'read');
        $this->assertSame(TenantContext::class, $reader->getParameters()[0]->getType()->getName());
        $this->assertSame(MembershipSelection::class, $reader->getParameters()[1]->getType()->getName());
        $this->assertSame('array', $reader->getReturnType()->getName());
        foreach (['read', 'lock', 'update'] as $method) {
            $port = new ReflectionMethod(SchoolProfiles::class, $method);
            $this->assertSame(TenantContext::class, $port->getParameters()[0]->getType()->getName());
            $this->assertSame(SchoolProfile::class, $port->getReturnType()->getName());
        }
        $this->assertSame(SchoolProfileUpdate::class, (new ReflectionMethod(SchoolProfiles::class, 'update'))->getParameters()[1]->getType()->getName());
    }
}

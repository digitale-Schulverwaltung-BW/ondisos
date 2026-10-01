<?php
declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\EditorAccess;
use App\Forms\FormConfigSchema as S;
use PHPUnit\Framework\TestCase;

class EditorAccessTest extends TestCase
{
    public function testSingleTenantInstallationOperatorHasThePlatformRole(): void
    {
        $this->assertSame(S::ROLE_PLATFORM, EditorAccess::roleFor([], false));
        $this->assertSame(S::ROLE_PLATFORM, EditorAccess::roleFor(['admin_logged_in' => true], false));
    }

    public function testMultiTenantPlatformAdmin(): void
    {
        $this->assertSame(S::ROLE_PLATFORM, EditorAccess::roleFor(['admin_logged_in' => true, 'is_platform_admin' => true], true));
    }

    public function testMultiTenantTenantAdminGetsTheRestrictedRole(): void
    {
        $this->assertSame(S::ROLE_TENANT, EditorAccess::roleFor(['admin_logged_in' => true, 'is_platform_admin' => false, 'tenant_id' => 7], true));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function noAccess(): array
    {
        return [
            'empty session'              => [[]],
            'logged in without tenant'   => [['admin_logged_in' => true]],
            'tenant id but not logged in' => [['tenant_id' => 3]],
            'platform flag false'        => [['admin_logged_in' => true, 'is_platform_admin' => false]],
            'tenant id zero'             => [['admin_logged_in' => true, 'tenant_id' => 0]],
        ];
    }

    /** @param array<string,mixed> $session */
    #[\PHPUnit\Framework\Attributes\DataProvider('noAccess')]
    public function testMultiTenantWithoutAValidRoleHasNoAccess(array $session): void
    {
        $this->assertNull(EditorAccess::roleFor($session, true));
    }
}

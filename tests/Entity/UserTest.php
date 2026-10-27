<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class UserTest extends TestCase
{
    use ClockSensitiveTrait;

    public function testAClientUserIsExternalWithTheUserRoleOnly(): void
    {
        self::mockTime('2026-03-03 10:00:00');
        $user = new User(' Claire@Example.com ', ' Claire ', ' Martin ');

        self::assertSame('claire@example.com', $user->getEmail());
        self::assertSame('claire@example.com', $user->getUserIdentifier());
        self::assertSame('Claire', $user->getFirstName());
        self::assertSame('Martin', $user->getLastName());
        self::assertSame('Claire Martin', $user->getFullName());
        self::assertTrue($user->isExternal());
        self::assertFalse($user->isAdmin());
        self::assertTrue($user->isActive());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertSame('2026-03-03 10:00:00', $user->getCreatedAt()->format('Y-m-d H:i:s'), 'created at the clock time');
    }

    public function testAnAdministratorIsAnInternalUser(): void
    {
        $user = new User('thomas@agency.test', 'Thomas', 'Girard');
        $user->makeInternal(admin: true);

        self::assertTrue($user->isInternal());
        self::assertTrue($user->isAdmin());
        self::assertSame(['ROLE_USER', 'ROLE_INTERNAL', 'ROLE_ADMIN'], $user->getRoles());
    }

    public function testBecomingExternalDropsTheAdministratorStatus(): void
    {
        $user = new User('thomas@agency.test', 'Thomas', 'Girard', internal: true);
        $user->makeInternal(admin: true);

        $user->makeExternal();

        self::assertTrue($user->isExternal());
        self::assertFalse($user->isAdmin());
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }
}

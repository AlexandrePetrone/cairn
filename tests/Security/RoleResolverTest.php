<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\RoleResolver;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test roles, broadest first: project_manager, developer (global), client.
 */
final class RoleResolverTest extends KernelTestCase
{
    use KernelHelpers;

    private RoleResolver $resolver;
    private AccessFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->resolver = self::getContainer()->get(RoleResolver::class);
        $this->fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testAnExternalUserWithoutMembershipHasNoRole(): void
    {
        $user = $this->fixtures->user('claire@client.test');

        self::assertNull($this->resolver->roleOn($user, $this->fixtures->shop));
        self::assertNull($this->resolver->roleOn($user, $this->fixtures->workspace));
    }

    public function testAWorkspaceMembershipAppliesToEveryProjectOfTheWorkspace(): void
    {
        $user = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnWorkspace($user, $this->fixtures->workspace, 'client');

        self::assertSame('client', $this->resolver->roleOn($user, $this->fixtures->workspace));
        self::assertSame('client', $this->resolver->roleOn($user, $this->fixtures->shop));
        self::assertSame('client', $this->resolver->roleOn($user, $this->fixtures->intranet));
        self::assertNull($this->resolver->roleOn($user, $this->fixtures->otherProject));
    }

    public function testAProjectMembershipOnlyWidensTheInheritedRole(): void
    {
        $user = $this->fixtures->user('thomas@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($user, $this->fixtures->workspace, 'developer');
        $this->fixtures->grantOnProject($user, $this->fixtures->shop, 'project_manager');
        $this->fixtures->grantOnProject($user, $this->fixtures->intranet, 'client');

        self::assertSame('project_manager', $this->resolver->roleOn($user, $this->fixtures->shop), 'widened on this project');
        self::assertSame('developer', $this->resolver->roleOn($user, $this->fixtures->intranet), 'a narrower grant does not apply');
    }

    public function testInternalUsersHoldTheGlobalRolesEverywhere(): void
    {
        $user = $this->fixtures->user('nadia@agency.test', internal: true);

        self::assertSame('developer', $this->resolver->roleOn($user, $this->fixtures->otherProject));
        self::assertSame('developer', $this->resolver->roleOn($user, $this->fixtures->otherWorkspace));
    }

    public function testAMembershipRoleUnknownToTheConfigurationIsIgnored(): void
    {
        $user = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnProject($user, $this->fixtures->shop, 'former_role');

        self::assertNull($this->resolver->roleOn($user, $this->fixtures->shop));
    }
}

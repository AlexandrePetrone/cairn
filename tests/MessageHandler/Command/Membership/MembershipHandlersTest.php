<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Membership;

use App\Entity\Membership;
use App\Message\Command\Membership\GrantMembership;
use App\Message\Command\Membership\RevokeMembership;
use App\Message\CommandBus;
use App\MessageHandler\Command\Membership\MembershipException;
use App\Repository\MembershipRepository;
use App\Security\AccessControl;
use App\Tests\KernelHelpers;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\ValidationFailedException;

/**
 * GrantMembership and RevokeMembership. Test roles, broadest first:
 * project_manager and developer (internal only), then client.
 */
final class MembershipHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private AccessFixtures $fixtures;
    private MembershipRepository $memberships;
    private AccessControl $access;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
        $this->memberships = self::getContainer()->get(MembershipRepository::class);
        $this->access = self::getContainer()->get(AccessControl::class);
    }

    public function testARoleOnAWorkspaceAppliesToItsProjectsAndIsChangedInPlace(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $workspaceId = $this->fixtures->workspace->getId();

        $this->commands->dispatch(new GrantMembership((int) $claire->getId(), $workspaceId, null, 'client'));
        self::assertSame('client', $this->access->roleOn($claire, $this->fixtures->shop));

        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->commands->dispatch(new GrantMembership((int) $nadia->getId(), $workspaceId, null, 'client'));
        $this->commands->dispatch(new GrantMembership((int) $nadia->getId(), $workspaceId, null, 'developer'));

        self::assertCount(1, $this->memberships->findByUser($nadia), 'one membership per workspace');
        self::assertSame('developer', $this->access->roleOn($nadia, $this->fixtures->intranet));
    }

    public function testAnInternalOnlyRoleNeverGoesToAnExternalUser(): void
    {
        $claire = $this->fixtures->user('claire@client.test');

        $this->expectException(MembershipException::class);
        $this->expectExceptionMessage('external');
        $this->commands->dispatch(new GrantMembership((int) $claire->getId(), null, $this->fixtures->shop->getId(), 'developer'));
    }

    public function testTheRoleMustExistInTheConfiguration(): void
    {
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);

        $this->expectException(MembershipException::class);
        $this->commands->dispatch(new GrantMembership((int) $nadia->getId(), $this->fixtures->workspace->getId(), null, 'architect'));
    }

    public function testAMembershipGoesOnAWorkspaceOrOnAProject(): void
    {
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);

        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new GrantMembership((int) $nadia->getId(), $this->fixtures->workspace->getId(), $this->fixtures->shop->getId(), 'developer'));
    }

    public function testARoleOnAProjectMustWidenTheOneInheritedFromItsWorkspace(): void
    {
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($nadia, $this->fixtures->workspace, 'developer');

        $this->commands->dispatch(new GrantMembership((int) $nadia->getId(), null, $this->fixtures->shop->getId(), 'project_manager'));
        self::assertSame('project_manager', $this->access->roleOn($nadia, $this->fixtures->shop));

        foreach (['developer', 'client'] as $role) {
            try {
                $this->commands->dispatch(new GrantMembership((int) $nadia->getId(), null, $this->fixtures->intranet->getId(), $role));
                self::fail(\sprintf('"%s" does not widen "developer".', $role));
            } catch (MembershipException $e) {
                self::assertStringContainsString('does not widen', $e->getMessage());
            }
        }
    }

    public function testRevokingDeletesTheMembership(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnWorkspace($claire, $this->fixtures->workspace, 'client');
        $membership = $this->memberships->findByUser($claire)[0];
        self::assertInstanceOf(Membership::class, $membership);

        $this->commands->dispatch(new RevokeMembership((int) $membership->getId()));

        self::assertSame([], $this->memberships->findByUser($claire));
        self::assertNull($this->access->roleOn($claire, $this->fixtures->shop), 'the access control forgets it at once');
    }
}

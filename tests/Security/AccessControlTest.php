<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\AccessControl;
use App\Security\Permission;
use App\Tests\AppliesConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Test permissions: issue.create for everyone, tracker.manage and
 * project.create for project managers only.
 */
final class AccessControlTest extends KernelTestCase
{
    use AppliesConfiguration;

    private AccessControl $access;
    private AccessFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->access = self::getContainer()->get(AccessControl::class);
        $this->fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testAdministratorsMayDoEverything(): void
    {
        $admin = $this->fixtures->user('admin@agency.test', admin: true);

        self::assertTrue($this->access->canView($admin, $this->fixtures->otherProject));
        self::assertTrue($this->access->isGranted($admin, Permission::TrackerManage, $this->fixtures->otherProject));
        self::assertTrue($this->access->isGranted($admin, Permission::WorkspaceManage, $this->fixtures->otherWorkspace));
    }

    public function testInternalUsersSeeEveryProjectButActThroughTheirRole(): void
    {
        $developer = $this->fixtures->user('nadia@agency.test', internal: true);

        self::assertTrue($this->access->canView($developer, $this->fixtures->otherProject));
        self::assertSame('developer', $this->access->roleOn($developer, $this->fixtures->otherProject));
        self::assertTrue($this->access->isGranted($developer, Permission::IssueCreate, $this->fixtures->otherProject));
        self::assertFalse($this->access->isGranted($developer, Permission::TrackerManage, $this->fixtures->otherProject));
    }

    public function testExternalUsersNeedAMembership(): void
    {
        $client = $this->fixtures->user('claire@client.test');

        self::assertFalse($this->access->canView($client, $this->fixtures->shop));
        self::assertFalse($this->access->isGranted($client, Permission::IssueCreate, $this->fixtures->shop));

        $this->fixtures->grantOnWorkspace($client, $this->fixtures->workspace, 'client');

        self::assertTrue($this->access->canView($client, $this->fixtures->shop));
        self::assertTrue($this->access->isGranted($client, Permission::IssueCreate, $this->fixtures->shop));
        self::assertFalse($this->access->canView($client, $this->fixtures->otherProject));
    }

    public function testTheVoterAnswersIsGrantedForTheCurrentUser(): void
    {
        $manager = $this->fixtures->user('thomas@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($manager, $this->fixtures->workspace, 'project_manager');
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($manager, 'main', $manager->getRoles()));
        $checker = self::getContainer()->get(AuthorizationCheckerInterface::class);

        self::assertTrue($checker->isGranted('view', $this->fixtures->shop));
        self::assertTrue($checker->isGranted('tracker.manage', $this->fixtures->shop));
        self::assertTrue($checker->isGranted('project.create', $this->fixtures->workspace));
        self::assertFalse($checker->isGranted('tracker.manage', $this->fixtures->otherProject), 'only a developer there');
        self::assertFalse($checker->isGranted('workspace.manage', $this->fixtures->workspace), 'administrators only');
    }
}

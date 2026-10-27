<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Config\Snapshot\ConfigRegistry;
use App\Entity\Issue;
use App\Entity\Tracker;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Tests\KernelHelpers;
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
    use KernelHelpers;

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

    public function testTransitionsAreAllowedByRole(): void
    {
        $workflow = self::getContainer()->get(ConfigRegistry::class)->definition()->workflow('bug');
        $client = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnProject($client, $this->fixtures->shop, 'client');
        $developer = $this->fixtures->user('nadia@agency.test', internal: true);
        $admin = $this->fixtures->user('admin@agency.test', admin: true);

        self::assertTrue($this->access->canTransition($client, $workflow->transition('accept'), $this->fixtures->shop));
        self::assertFalse($this->access->canTransition($client, $workflow->transition('start'), $this->fixtures->shop));
        self::assertTrue($this->access->canTransition($developer, $workflow->transition('start'), $this->fixtures->shop));
        self::assertFalse($this->access->canTransition($developer, $workflow->transition('accept'), $this->fixtures->shop));
        self::assertTrue($this->access->canTransition($admin, $workflow->transition('accept'), $this->fixtures->otherProject));
    }

    public function testExternalsOnlySeePublicIssuesOfTheirProjects(): void
    {
        $client = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnWorkspace($client, $this->fixtures->workspace, 'client');
        $developer = $this->fixtures->user('nadia@agency.test', internal: true);
        $tracker = new Tracker($this->fixtures->shop, 'Anomalie', 'bug');
        $issue = new Issue($this->fixtures->shop, $tracker, 1, $client, 'Export', '');

        self::assertTrue($this->access->canSee($client, $issue));
        $issue->makePrivate();
        self::assertFalse($this->access->canSee($client, $issue));
        self::assertTrue($this->access->canSee($developer, $issue));

        $elsewhere = new Issue($this->fixtures->otherProject, new Tracker($this->fixtures->otherProject, 'Anomalie', 'bug'), 1, $client, 'Ailleurs', '');
        self::assertFalse($this->access->canSee($client, $elsewhere), 'no access to that project');
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

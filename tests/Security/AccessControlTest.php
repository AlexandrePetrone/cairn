<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Config\ConfigRegistry;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\Tracker;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\PlaceMove;
use App\Tests\Config\Fixture\DefinitionFactory;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Test roles, broadest first: project_manager, developer, client.
 * Test permissions: issue.create for everyone, issue.set_private,
 * issue.edit and comment.edit for project managers only.
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

    public function testAnExternalUserWithoutMembershipHasNoRole(): void
    {
        $user = $this->fixtures->user('claire@client.test');

        self::assertNull($this->access->roleOn($user, $this->fixtures->shop));
        self::assertNull($this->access->roleOn($user, $this->fixtures->workspace));
    }

    public function testAWorkspaceMembershipAppliesToEveryProjectOfTheWorkspace(): void
    {
        $user = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnWorkspace($user, $this->fixtures->workspace, 'client');

        self::assertSame('client', $this->access->roleOn($user, $this->fixtures->workspace));
        self::assertSame('client', $this->access->roleOn($user, $this->fixtures->shop));
        self::assertSame('client', $this->access->roleOn($user, $this->fixtures->intranet));
        self::assertNull($this->access->roleOn($user, $this->fixtures->otherProject));
    }

    public function testAProjectMembershipOnlyWidensTheInheritedRole(): void
    {
        $user = $this->fixtures->user('thomas@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($user, $this->fixtures->workspace, 'developer');
        $this->fixtures->grantOnProject($user, $this->fixtures->shop, 'project_manager');
        $this->fixtures->grantOnProject($user, $this->fixtures->intranet, 'client');

        self::assertSame('project_manager', $this->access->roleOn($user, $this->fixtures->shop), 'widened on this project');
        self::assertSame('developer', $this->access->roleOn($user, $this->fixtures->intranet), 'a narrower grant does not apply');
    }

    public function testBeingInternalGrantsNoRole(): void
    {
        $user = $this->fixtures->user('nadia@agency.test', internal: true);

        self::assertNull($this->access->roleOn($user, $this->fixtures->otherProject));
        self::assertNull($this->access->roleOn($user, $this->fixtures->otherWorkspace));
    }

    public function testAdministratorsMayDoEverything(): void
    {
        $admin = $this->fixtures->user('admin@agency.test', admin: true);

        self::assertTrue($this->access->canView($admin, $this->fixtures->otherProject));
        self::assertTrue($this->access->isGranted($admin, Permission::IssueSetPrivate, $this->fixtures->otherProject));
        self::assertTrue($this->access->isGranted($admin, Permission::IssueCreate, $this->fixtures->otherWorkspace));
    }

    public function testAnArchivedProjectStaysReadableButGrantsNothing(): void
    {
        $admin = $this->fixtures->user('admin@agency.test', admin: true);
        $this->fixtures->shop->archive();

        self::assertTrue($this->access->canView($admin, $this->fixtures->shop));
        self::assertFalse($this->access->isGranted($admin, Permission::IssueCreate, $this->fixtures->shop), 'not even an administrator');
        self::assertTrue($this->access->isGranted($admin, Permission::IssueCreate, $this->fixtures->intranet));
    }

    public function testTheProjectsOfAnArchivedWorkspaceAreReadOnlyToo(): void
    {
        $admin = $this->fixtures->user('admin@agency.test', admin: true);
        $this->fixtures->workspace->archive();

        self::assertTrue($this->access->canView($admin, $this->fixtures->intranet));
        self::assertFalse($this->access->isGranted($admin, Permission::IssueCreate, $this->fixtures->intranet));
        self::assertFalse($this->access->isGranted($admin, Permission::IssueCreate, $this->fixtures->workspace));
        self::assertTrue($this->access->isGranted($admin, Permission::IssueCreate, $this->fixtures->otherProject));
    }

    public function testInternalUsersSeeEveryProjectButActThroughTheirRole(): void
    {
        $developer = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->fixtures->grantOnProject($developer, $this->fixtures->shop, 'developer');

        self::assertTrue($this->access->canView($developer, $this->fixtures->otherProject));
        self::assertFalse($this->access->isGranted($developer, Permission::IssueCreate, $this->fixtures->otherProject), 'no role there');
        self::assertTrue($this->access->isGranted($developer, Permission::IssueCreate, $this->fixtures->shop));
        self::assertFalse($this->access->isGranted($developer, Permission::IssueSetPrivate, $this->fixtures->shop));
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
        $this->fixtures->grantOnWorkspace($developer, $this->fixtures->workspace, 'developer');
        $admin = $this->fixtures->user('admin@agency.test', admin: true);

        self::assertTrue($this->access->canTransition($client, $workflow->transition('accept'), $this->fixtures->shop));
        self::assertFalse($this->access->canTransition($client, $workflow->transition('start'), $this->fixtures->shop));
        self::assertTrue($this->access->canTransition($developer, $workflow->transition('start'), $this->fixtures->shop));
        self::assertFalse($this->access->canTransition($developer, $workflow->transition('accept'), $this->fixtures->shop));
        self::assertTrue($this->access->canTransition($admin, $workflow->transition('accept'), $this->fixtures->otherProject));
    }

    public function testNoTransitionIsAllowedOnAReadOnlyProject(): void
    {
        $workflow = self::getContainer()->get(ConfigRegistry::class)->definition()->workflow('bug');
        $developer = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($developer, $this->fixtures->workspace, 'developer');
        $admin = $this->fixtures->user('admin@agency.test', admin: true);

        $this->fixtures->shop->archive();
        self::assertFalse($this->access->canTransition($developer, $workflow->transition('start'), $this->fixtures->shop));
        self::assertFalse($this->access->canTransition($admin, $workflow->transition('start'), $this->fixtures->shop), 'not even an administrator');

        $this->fixtures->workspace->archive();
        self::assertFalse($this->access->canTransition($admin, $workflow->transition('start'), $this->fixtures->intranet));
        self::assertTrue($this->access->canTransition($admin, $workflow->transition('start'), $this->fixtures->otherProject));
    }

    public function testTheTransitionsOfAnIssueAreThoseLeavingItsPlaceThatTheRoleMayTrigger(): void
    {
        $client = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnProject($client, $this->fixtures->shop, 'client');
        $developer = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($developer, $this->fixtures->workspace, 'developer');
        $tracker = new Tracker($this->fixtures->shop, 'Bug', 'bug');
        $issue = new Issue($this->fixtures->shop, $tracker, DefinitionFactory::place('to_test'), 1, $client, 'Export', '');

        $ids = static fn (array $transitions): array => array_map(static fn ($transition): string => $transition->id, $transitions);
        self::assertSame(['accept', 'refuse'], $ids($this->access->transitionsFor($client, $issue)));
        self::assertSame([], $this->access->transitionsFor($developer, $issue), 'nothing leaving to_test is a developer\'s');

        $this->fixtures->shop->archive();
        self::assertSame([], $this->access->transitionsFor($client, $issue), 'an archived project allows nothing');
    }

    public function testMovesFollowTheWorkflowFirstThenLeaveItForWhoeverMay(): void
    {
        $client = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnWorkspace($client, $this->fixtures->workspace, 'client');
        $developer = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($developer, $this->fixtures->workspace, 'developer');
        $manager = $this->fixtures->user('thomas@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($manager, $this->fixtures->workspace, 'project_manager');
        $tracker = new Tracker($this->fixtures->shop, 'Bug', 'bug');
        $issue = new Issue($this->fixtures->shop, $tracker, DefinitionFactory::place('to_test'), 1, $client, 'Export', '');

        $moves = static fn (array $moves): array => array_map(static fn (PlaceMove $move): string => $move->place->id.':'.($move->transition->id ?? 'forced'), $moves);
        self::assertSame(['in_progress:refuse', 'done:accept'], $moves($this->access->movesFor($client, $issue)), 'the actions of the role, in the order of the places');
        self::assertSame([], $this->access->movesFor($developer, $issue), 'no action, and no issue.force_place');
        self::assertSame(
            ['new:forced', 'in_progress:refuse', 'done:accept'],
            $moves($this->access->movesFor($manager, $issue)),
            'an action wherever one leads, outside the workflow elsewhere, never to the place the issue is on',
        );

        $this->fixtures->shop->archive();
        self::assertSame([], $this->access->movesFor($manager, $issue), 'an archived project allows nothing');
    }

    public function testExternalsOnlySeePublicIssuesOfTheirProjects(): void
    {
        $client = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnWorkspace($client, $this->fixtures->workspace, 'client');
        $developer = $this->fixtures->user('nadia@agency.test', internal: true);
        $tracker = new Tracker($this->fixtures->shop, 'Bug', 'bug');
        $issue = new Issue($this->fixtures->shop, $tracker, DefinitionFactory::place('new'), 1, $client, 'Export', '');

        self::assertTrue($this->access->canSee($client, $issue));
        $issue->makePrivate();
        self::assertFalse($this->access->canSee($client, $issue));
        self::assertTrue($this->access->canSee($developer, $issue));

        $elsewhere = new Issue($this->fixtures->otherProject, new Tracker($this->fixtures->otherProject, 'Bug', 'bug'), DefinitionFactory::place('new'), 1, $client, 'Elsewhere', '');
        self::assertFalse($this->access->canSee($client, $elsewhere), 'no access to that project');
    }

    /**
     * Test permissions: issue.edit and comment.edit for project managers
     * only, issue.create and comment.create for every role.
     */
    public function testContentIsEditedByItsAuthorOrWithTheRightOnOthers(): void
    {
        $client = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnWorkspace($client, $this->fixtures->workspace, 'client');
        $otherClient = $this->fixtures->user('paul@client.test');
        $this->fixtures->grantOnWorkspace($otherClient, $this->fixtures->workspace, 'client');
        $developer = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($developer, $this->fixtures->workspace, 'developer');
        $manager = $this->fixtures->user('thomas@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($manager, $this->fixtures->workspace, 'project_manager');
        $onlooker = $this->fixtures->user('lea@agency.test', internal: true);
        $admin = $this->fixtures->user('admin@agency.test', internal: true, admin: true);

        $tracker = new Tracker($this->fixtures->shop, 'Bug', 'bug');
        $issue = new Issue($this->fixtures->shop, $tracker, DefinitionFactory::place('new'), 1, $client, 'Export', '');
        $comment = new Comment($issue, $developer, 'I am looking into it.');
        $privateComment = new Comment($issue, $developer, 'Estimate.', private: true);

        self::assertTrue($this->access->canEditIssue($client, $issue), 'the author');
        self::assertFalse($this->access->canEditIssue($otherClient, $issue), 'another client');
        self::assertFalse($this->access->canEditIssue($developer, $issue), 'a developer may create, not edit the issues of others');
        self::assertTrue($this->access->canEditIssue($manager, $issue), 'issue.edit');
        self::assertFalse($this->access->canEditIssue($onlooker, $issue), 'internal without a role');
        self::assertTrue($this->access->canEditIssue($admin, $issue));

        self::assertTrue($this->access->canEditComment($developer, $comment), 'the author');
        self::assertFalse($this->access->canEditComment($client, $comment), 'another author');
        self::assertTrue($this->access->canEditComment($manager, $comment), 'comment.edit');
        self::assertFalse($this->access->canEditComment($onlooker, $comment));
        self::assertTrue($this->access->canEditComment($admin, $comment));
        self::assertFalse($this->access->canEditComment($client, $privateComment), 'cannot even see it');
        self::assertTrue($this->access->canEditComment($manager, $privateComment));

        $this->fixtures->shop->archive();
        self::assertFalse($this->access->canEditIssue($client, $issue), 'archived: nobody');
        self::assertFalse($this->access->canEditIssue($admin, $issue));
        self::assertFalse($this->access->canEditComment($developer, $comment));
    }

    public function testTheVoterAnswersIsGrantedForTheCurrentUser(): void
    {
        $manager = $this->fixtures->user('thomas@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($manager, $this->fixtures->workspace, 'project_manager');
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($manager, 'main', $manager->getRoles()));
        $checker = self::getContainer()->get(AuthorizationCheckerInterface::class);

        self::assertTrue($checker->isGranted('view', $this->fixtures->shop));
        self::assertTrue($checker->isGranted('issue.set_private', $this->fixtures->shop));
        self::assertTrue($checker->isGranted('issue.create', $this->fixtures->workspace));
        self::assertFalse($checker->isGranted('issue.set_private', $this->fixtures->otherProject), 'no role there');
    }
}

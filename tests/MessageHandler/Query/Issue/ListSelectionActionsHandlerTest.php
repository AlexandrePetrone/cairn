<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Issue;

use App\Entity\Issue;
use App\Entity\User;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\CommandBus;
use App\Message\Query\Issue\ListSelectionActions;
use App\Message\Query\Issue\SelectionActions;
use App\Message\Query\Issue\SelectionTransition;
use App\Message\QueryBus;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * On the shop of the scene, under the example configuration: bugs,
 * evolutions and internal tasks follow three workflows that share some
 * transition ids (`start`, `deliver`, `accept`, `refuse`, `reopen`).
 * Nadia develops on the shop, Thomas manages the workspace, Claire is its
 * client.
 */
final class ListSelectionActionsHandlerTest extends KernelTestCase
{
    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testIssuesOfOneWorkflowAtOnePlaceShareTheirActions(): void
    {
        $actions = $this->actions('nadia.benali@cairn.test', 'MD-SPT-1', 'MD-SPT-2');

        self::assertSame(['MD-SPT-1', 'MD-SPT-2'], $this->keys($actions), 'sorted as the lists: blocking first');
        self::assertSame(['qualify', 'reject'], $this->transitionIds($actions));
        self::assertTrue($actions->canAssign);
        self::assertTrue($actions->canComment);
        self::assertTrue($actions->canCommentPrivately);
        self::assertTrue($actions->canSetCriticality);
        self::assertContains('nadia.benali@cairn.test', $this->emails($actions->assignees));
        self::assertContains('claire.martin@maison-dupont.test', $this->emails($actions->assignees), 'a member through the workspace');
    }

    public function testTheSameTransitionIdMatchesAcrossWorkflows(): void
    {
        $this->commands()->dispatch(new ApplyTransition((int) $this->user('nadia.benali@cairn.test')->getId(), 'MD-SPT-10', 'start'));

        $actions = $this->actions('nadia.benali@cairn.test', 'MD-SPT-3', 'MD-SPT-10');

        self::assertSame(['deliver'], $this->transitionIds($actions), 'a bug and an evolution in progress are both delivered');
        self::assertSame('Livrer en recette', $actions->transitions[0]->label, 'one label when the workflows agree');
        self::assertTrue($actions->offers('transition:deliver'));
        self::assertFalse($actions->offers('transition:qualify'));
    }

    public function testNothingIsOfferedThatDoesNotApplyToEveryIssue(): void
    {
        $actions = $this->actions('nadia.benali@cairn.test', 'MD-SPT-1', 'MD-SPT-8');

        self::assertSame([], $this->transitionIds($actions), 'a new bug and an evolution to estimate have no action in common');
        self::assertTrue($actions->canAssign, 'what does not depend on the workflow stays');
        self::assertTrue($actions->hasAnything());
    }

    public function testAClientGetsTheActionsOfHerRoleOnIssuesSheSees(): void
    {
        $actions = $this->actions('claire.martin@maison-dupont.test', 'MD-SPT-4', 'MD-SPT-6');

        self::assertSame(['MD-SPT-4'], $this->keys($actions), 'the internal task is not hers to see');
        self::assertSame(['accept', 'refuse'], $this->transitionIds($actions));
        $refuse = $actions->transitionFor('transition:refuse');
        self::assertInstanceOf(SelectionTransition::class, $refuse);
        self::assertTrue($refuse->requiresComment);
        self::assertFalse($actions->canAssign);
        self::assertSame([], $actions->assignees);
        self::assertTrue($actions->canComment);
        self::assertFalse($actions->canCommentPrivately);
        self::assertTrue($actions->canSetCriticality);
    }

    public function testUnknownIdsAreDropped(): void
    {
        $actions = self::getContainer()->get(QueryBus::class)->ask(new ListSelectionActions($this->user('nadia.benali@cairn.test'), [999_999]));

        self::assertSame([], $actions->issues);
        self::assertFalse($actions->hasAnything());
    }

    public function testARightCountsOnlyWhenItHoldsOnEveryProject(): void
    {
        $intranetBug = $this->scene->intranetBug();

        $nadia = $this->actions('nadia.benali@cairn.test', 'MD-SPT-1', $intranetBug->getKey());
        self::assertCount(2, $nadia->issues, 'internal users see every project');
        self::assertTrue($nadia->isAcrossProjects());
        self::assertSame([], $this->transitionIds($nadia), 'she holds no role on the intranet');
        self::assertFalse($nadia->canAssign);
        self::assertFalse($nadia->hasAnything());

        $thomas = $this->actions('thomas.girard@cairn.test', 'MD-SPT-1', $intranetBug->getKey());
        self::assertSame(['qualify', 'reject'], $this->transitionIds($thomas));
        self::assertTrue($thomas->canAssign);
        $assignees = $this->emails($thomas->assignees);
        self::assertContains('thomas.girard@cairn.test', $assignees, 'a member of the whole workspace');
        self::assertNotContains('nadia.benali@cairn.test', $assignees, 'a member of the shop only');
        self::assertNotContains('marc.petit@cairn.test', $assignees, 'a member of the intranet only');
    }

    private function actions(string $email, string ...$keys): SelectionActions
    {
        $ids = array_map(fn (string $key): int => (int) $this->issueId($key), array_values($keys));

        return self::getContainer()->get(QueryBus::class)->ask(new ListSelectionActions($this->user($email), $ids));
    }

    private function issueId(string $key): ?int
    {
        $issue = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Issue::class)->findOneBy(['key' => $key]);

        return $issue?->getId();
    }

    /**
     * @return list<string>
     */
    private function keys(SelectionActions $actions): array
    {
        return array_map(static fn (Issue $issue): string => $issue->getKey(), $actions->issues);
    }

    /**
     * @return list<string>
     */
    private function transitionIds(SelectionActions $actions): array
    {
        $ids = array_map(static fn (SelectionTransition $transition): string => $transition->id, $actions->transitions);
        sort($ids);

        return $ids;
    }

    /**
     * @param list<User> $users
     *
     * @return list<string>
     */
    private function emails(array $users): array
    {
        return array_map(static fn (User $user): string => $user->getEmail(), $users);
    }

    private function user(string $email): User
    {
        return $this->scene->user($email);
    }

    private function commands(): CommandBus
    {
        return self::getContainer()->get(CommandBus::class);
    }
}

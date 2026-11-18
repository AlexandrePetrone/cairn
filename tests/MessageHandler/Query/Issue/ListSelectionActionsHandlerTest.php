<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Issue;

use App\Entity\Issue;
use App\Entity\User;
use App\Message\Query\Issue\ListSelectionActions;
use App\Message\Query\Issue\SelectionActions;
use App\Message\QueryBus;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * On the shop of the scene, under the example configuration: bugs,
 * evolutions and internal tasks follow three workflows.
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

    public function testIssuesOfOneProjectShareWhatTheViewerMayChange(): void
    {
        $actions = $this->actions('nadia.benali@cairn.test', 'MD-SPT-1', 'MD-SPT-2');

        self::assertSame(['MD-SPT-1', 'MD-SPT-2'], $this->keys($actions), 'sorted as the lists: blocking first');
        self::assertSame($this->scene->shop->getId(), $actions->project?->getId());
        self::assertTrue($actions->canAssign);
        self::assertTrue($actions->canComment);
        self::assertTrue($actions->canCommentPrivately);
        self::assertTrue($actions->canSetCriticality);
        self::assertTrue($actions->canSetPrivate);
        self::assertContains('nadia.benali@cairn.test', $this->emails($actions->assignees));
        self::assertContains('claire.martin@maison-dupont.test', $this->emails($actions->assignees), 'a member through the workspace');
    }

    public function testAClientGetsTheRightsOfHerRoleOnIssuesSheSees(): void
    {
        $actions = $this->actions('claire.martin@maison-dupont.test', 'MD-SPT-4', 'MD-SPT-6');

        self::assertSame(['MD-SPT-4'], $this->keys($actions), 'the internal task is not hers to see');
        self::assertFalse($actions->canAssign);
        self::assertSame([], $actions->assignees);
        self::assertTrue($actions->canComment);
        self::assertFalse($actions->canCommentPrivately);
        self::assertTrue($actions->canSetCriticality);
        self::assertFalse($actions->canSetPrivate);
    }

    public function testAMoveIsOfferedToTheTrackersValidForEveryIssue(): void
    {
        $names = static fn (SelectionActions $actions): array => array_map(static fn ($tracker): string => $tracker->getName(), $actions->moveTrackers);

        self::assertSame(['Bug', 'Feature', 'Internal task'], $names($this->actions('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-2')), 'the own tracker and the others, with issue.bypass_workflow');
        self::assertSame(['Bug', 'Feature', 'Internal task'], $names($this->actions('nadia.benali@cairn.test', 'MD-SPT-1', 'MD-SPT-7')), 'a bug and a feature: each tracker is the own of one and a change for the other');
        self::assertSame([], $names($this->actions('claire.martin@maison-dupont.test', 'MD-SPT-1', 'MD-SPT-2')));
        self::assertSame([], $names($this->actions('thomas.girard@cairn.test', 'MD-SPT-1', $this->scene->intranetBug()->getKey())), 'trackers belong to one project');
    }

    public function testThePlacesOfferedAreThoseEveryIssueHasInItsOwnTracker(): void
    {
        $ids = static fn (SelectionActions $actions): array => array_map(static fn ($place): string => $place->id, $actions->places);

        self::assertSame(
            ['new', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'],
            $ids($this->actions('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-6', 'MD-SPT-10')),
            'a bug, a task and a feature: what their three workflows share, in the order of the instance',
        );
        self::assertSame(
            ['new', 'needs_info', 'qualified', 'in_progress', 'blocked', 'to_test', 'to_release', 'done', 'declined'],
            $ids($this->actions('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-2')),
            'two bugs: every place of their workflow',
        );
        self::assertSame([], $ids($this->actions('claire.martin@maison-dupont.test', 'MD-SPT-1', 'MD-SPT-2')), 'a place outside the actions takes issue.bypass_workflow');
        self::assertSame([], $ids($this->actions('thomas.girard@cairn.test', 'MD-SPT-1', $this->scene->intranetBug()->getKey())), 'no step across projects');
    }

    public function testUnknownIdsAreDropped(): void
    {
        $actions = self::getContainer()->get(QueryBus::class)->ask(new ListSelectionActions($this->user('nadia.benali@cairn.test'), [999_999]));

        self::assertSame([], $actions->issues);
        self::assertFalse($actions->isAcrossProjects());
    }

    public function testARightCountsOnlyWhenItHoldsOnEveryProject(): void
    {
        $intranetBug = $this->scene->intranetBug();

        $nadia = $this->actions('nadia.benali@cairn.test', 'MD-SPT-1', $intranetBug->getKey());
        self::assertCount(2, $nadia->issues, 'internal users see every project');
        self::assertTrue($nadia->isAcrossProjects());
        self::assertNull($nadia->project);
        self::assertFalse($nadia->canAssign, 'she holds no role on the intranet');
        self::assertFalse($nadia->canSetPrivate);

        $thomas = $this->actions('thomas.girard@cairn.test', 'MD-SPT-1', $intranetBug->getKey());
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
}

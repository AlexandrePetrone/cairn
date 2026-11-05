<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Release;
use App\Entity\Tracker;
use App\Entity\User;
use App\Factory\MembershipFactory;
use App\Factory\ProjectFactory;
use App\Factory\TrackerFactory;
use App\Factory\UserFactory;
use App\Message\Query\Issue\IssueGrouping;
use App\Message\Query\Issue\IssueSort;
use App\Message\Query\Issue\SearchIssues;
use App\MessageHandler\Query\Issue\SearchIssuesHandler;
use App\Repository\Pagination;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Zenstruck\Foundry\Test\Factories;

/**
 * Test workflow `bug`: new and in_progress are open/in progress, to_test is
 * waiting, done is closed.
 */
final class SearchIssuesHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use KernelHelpers;

    private IssueBuilder $builder;
    private Project $project;
    private Tracker $bugs;
    private Tracker $tasks;
    private User $manager;
    private User $nadia;
    private User $client;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->builder = new IssueBuilder(self::getContainer()->get(EntityManagerInterface::class));

        $this->project = ProjectFactory::createOne();
        $this->bugs = TrackerFactory::createOne(['project' => $this->project, 'name' => 'Anomalie']);
        $this->tasks = TrackerFactory::createOne(['project' => $this->project, 'name' => 'Tâche', 'private' => true]);
        $this->manager = UserFactory::new()->internal()->create();
        $this->nadia = UserFactory::new()->internal()->create();
        $this->client = UserFactory::createOne();
        MembershipFactory::createOne(['user' => $this->client, 'workspace' => $this->project->getWorkspace(), 'role' => 'client']);
    }

    public function testListsAProjectSortedByStatusThenCriticalityThenLatestActivity(): void
    {
        self::mockTime('2026-10-01 10:00');
        $oldBlocking = $this->builder->issue($this->bugs, $this->client, 'Ancienne bloquante', criticality: Criticality::Blocking);
        self::mockTime('2026-10-02 10:00');
        $waiting = $this->builder->issue($this->bugs, $this->client, 'En recette', 'to_test', Criticality::Blocking);
        $newNormal = $this->builder->issue($this->bugs, $this->client, 'Récente normale');
        $newBlocking = $this->builder->issue($this->bugs, $this->client, 'Récente bloquante', criticality: Criticality::Blocking);
        $inProgress = $this->builder->issue($this->bugs, $this->client, 'En cours', 'in_progress', Criticality::Low);
        $this->builder->issue($this->bugs, $this->client, 'Fermée', 'done');
        $this->builder->issue(TrackerFactory::createOne(), $this->client, 'Autre projet');

        $result = $this->inProject();

        self::assertSame(['Récente bloquante', 'Ancienne bloquante', 'Récente normale', 'En cours', 'En recette'], self::titles($result->items));
        self::assertSame(5, $result->total, 'closed issues are hidden by default');
        self::assertSame([$newBlocking, $oldBlocking, $newNormal, $inProgress, $waiting], $result->items);
    }

    public function testClosedIssuesAreListedOnRequest(): void
    {
        $this->builder->issue($this->bugs, $this->client, 'Ouverte');
        $this->builder->issue($this->bugs, $this->client, 'Fermée', 'done');

        self::assertSame(['Ouverte', 'Fermée'], self::titles($this->inProject(closed: true)->items));
        self::assertSame(['Fermée'], self::titles($this->inProject(status: [PlaceStatus::Closed])->items), 'asking for closed issues lists them');
    }

    public function testEachFilter(): void
    {
        $this->builder->issue($this->bugs, $this->client, 'Export CSV vide', criticality: Criticality::High, assignee: $this->nadia);
        $this->builder->issue($this->bugs, $this->client, 'Spinner infini', 'in_progress');
        $this->builder->issue($this->tasks, $this->manager, 'Mettre à jour Symfony', assignee: $this->manager);

        self::assertSame(['Spinner infini'], self::titles($this->inProject(status: [PlaceStatus::InProgress])->items));
        self::assertSame(['Mettre à jour Symfony'], self::titles($this->inProject(tracker: [(int) $this->tasks->getId()])->items));
        self::assertSame(['Export CSV vide'], self::titles($this->inProject(criticality: [Criticality::High])->items));
        self::assertSame(['Export CSV vide'], self::titles($this->inProject(assignee: [(int) $this->nadia->getId()])->items));
        self::assertSame(['Spinner infini'], self::titles($this->inProject(assignee: [0])->items), '0 means unassigned');
        self::assertSame(['Export CSV vide', 'Spinner infini'], self::titles($this->inProject(assignee: [0, (int) $this->nadia->getId()])->items));
    }

    public function testAPlaceFilterListsTheChosenPlacesClosedOnesToo(): void
    {
        $this->builder->issue($this->bugs, $this->client, 'Nouvelle');
        $this->builder->issue($this->bugs, $this->client, 'En cours', 'in_progress');
        $this->builder->issue($this->bugs, $this->client, 'Terminée', 'done');

        self::assertSame(['En cours'], self::titles($this->search(place: ['bug:in_progress'])->items));
        self::assertSame(['Nouvelle', 'Terminée'], self::titles($this->search(place: ['bug:new', 'bug:done'])->items), 'a closed place chosen is listed');
    }

    public function testAColumnSortsTheListBeforeTheDefaultOrder(): void
    {
        $this->builder->issue($this->bugs, $this->client, 'banane');
        $this->builder->issue($this->bugs, $this->client, 'Abricot', 'in_progress');
        $this->builder->issue($this->bugs, $this->client, 'Cerise', criticality: Criticality::Blocking);

        self::assertSame(['Abricot', 'banane', 'Cerise'], self::titles($this->search(sort: IssueSort::Title)->items), 'whatever the case');
        self::assertSame(['Cerise', 'banane', 'Abricot'], self::titles($this->search(sort: IssueSort::Title, order: \SortDirection::Descending)->items));
        self::assertSame(['Cerise', 'banane', 'Abricot'], self::titles($this->search(sort: IssueSort::Criticality, order: \SortDirection::Descending)->items), 'blocking first, then the default order');
        self::assertSame(['Cerise', 'banane', 'Abricot'], self::titles($this->search(sort: IssueSort::Place)->items), 'in the order of the workflow, then the default order');
    }

    public function testAGroupedListKeepsEachGroupTogetherAndCountsIt(): void
    {
        $zoe = UserFactory::new()->internal()->create(['firstName' => 'Zoé', 'lastName' => 'Arnaud']);
        $marc = UserFactory::new()->internal()->create(['firstName' => 'Marc', 'lastName' => 'Petit']);
        $this->builder->issue($this->bugs, $this->client, 'Pour Marc', criticality: Criticality::Blocking, assignee: $marc);
        $this->builder->issue($this->bugs, $this->client, 'Personne');
        $this->builder->issue($this->bugs, $this->client, 'Pour Zoé', assignee: $zoe);
        $this->builder->issue($this->bugs, $this->client, 'Encore Marc', assignee: $marc);

        $result = $this->search(group: IssueGrouping::Assignee);

        self::assertSame(['Personne', 'Pour Zoé', 'Pour Marc', 'Encore Marc'], self::titles($result->items), 'nobody first, then by name, the default order inside');
        self::assertSame([IssueGrouping::NOBODY => 1, (string) $zoe->getId() => 1, (string) $marc->getId() => 2], $result->groupTotals);

        $sorted = $this->search(group: IssueGrouping::Assignee, sort: IssueSort::Title);
        self::assertSame(['Personne', 'Pour Zoé', 'Encore Marc', 'Pour Marc'], self::titles($sorted->items), 'the sort applies inside each group');

        self::assertSame(['bug:new' => 4], $this->search(group: IssueGrouping::Place)->groupTotals, 'a place is named with its workflow');
        self::assertSame([], $this->search()->groupTotals, 'nothing counted when not grouped');
    }

    public function testTextSearchMatchesTitlesAndExactKeys(): void
    {
        $export = $this->builder->issue($this->bugs, $this->client, 'L\'export CSV des commandes est vide');
        $this->builder->issue($this->bugs, $this->client, 'Spinner infini');

        self::assertSame([$export], $this->inProject(q: 'csv')->items);
        self::assertSame([$export], $this->inProject(q: mb_strtolower($export->getKey()))->items);
        self::assertSame([], $this->inProject(q: '100%')->items, 'wildcards are escaped');
    }

    public function testScopesAcrossProjects(): void
    {
        $other = TrackerFactory::createOne(['project' => ProjectFactory::createOne(['workspace' => $this->project->getWorkspace()])]);
        $mine = $this->builder->issue($this->bugs, $this->client, 'Assignée ici', assignee: $this->nadia);
        $mineElsewhere = $this->builder->issue($other, $this->manager, 'Assignée ailleurs', assignee: $this->nadia);
        $this->builder->issue($this->bugs, $this->manager, 'Pas à moi');

        // Same status and criticality: the latest activity comes first.
        self::assertSame([$mineElsewhere, $mine], self::queryBus()->ask(new SearchIssues($this->nadia, assigneeId: $this->nadia->getId()))->items);
        self::assertSame(['Pas à moi', 'Assignée ailleurs'], self::titles(self::queryBus()->ask(new SearchIssues($this->manager, authorId: $this->manager->getId()))->items));
        self::assertSame(3, self::queryBus()->ask(new SearchIssues($this->manager, workspaceId: $this->project->getWorkspace()->getId()))->total);
        self::assertSame([$mineElsewhere], self::queryBus()->ask(new SearchIssues($this->nadia, assigneeId: $this->nadia->getId(), project: [(int) $other->getProject()->getId()]))->items, 'the project filter');
    }

    public function testAnInvalidPageIsRefusedBeforeReachingTheHandler(): void
    {
        $this->expectException(ValidationFailedException::class);
        self::queryBus()->ask(new SearchIssues($this->manager, projectId: $this->project->getId(), page: 0));
    }

    public function testExternalUsersDoNotSeePrivateIssues(): void
    {
        $this->builder->issue($this->bugs, $this->client, 'Publique');
        $this->builder->issue($this->bugs, $this->manager, 'Privée', private: true);
        $this->builder->issue($this->tasks, $this->manager, 'Tâche interne');

        self::assertSame(['Publique'], self::titles($this->inProject(viewer: $this->client)->items));
        self::assertSame(3, $this->inProject(viewer: $this->manager)->total);
    }

    public function testFiltersAndGroupsByRelease(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $later = new Release($this->project, '2.6.0');
        $later->plan(new \DateTimeImmutable('2026-12-01'));
        $sooner = new Release($this->project, '2.5.0');
        $sooner->plan(new \DateTimeImmutable('2026-11-15'));
        $entityManager->persist($later);
        $entityManager->persist($sooner);
        $inSooner = $this->builder->issue($this->bugs, $this->client, 'Dans la 2.5.0');
        $inSooner->scheduleIn($sooner);
        $inLater = $this->builder->issue($this->bugs, $this->client, 'Dans la 2.6.0');
        $inLater->scheduleIn($later);
        $this->builder->issue($this->bugs, $this->client, 'Sans version');
        $entityManager->flush();

        self::assertSame(['Dans la 2.5.0'], self::titles(self::queryBus()->ask(new SearchIssues($this->manager, projectId: $this->project->getId(), release: [(int) $sooner->getId()]))->items));
        self::assertEqualsCanonicalizing(['Dans la 2.6.0', 'Sans version'], self::titles(self::queryBus()->ask(new SearchIssues($this->manager, projectId: $this->project->getId(), release: [(int) $later->getId(), 0]))->items));

        $grouped = $this->search(group: IssueGrouping::Release);
        self::assertSame(['Dans la 2.5.0', 'Dans la 2.6.0', 'Sans version'], self::titles($grouped->items), 'the release planned first comes first, the issues in no release last');
        self::assertEquals([(string) $sooner->getId() => 1, (string) $later->getId() => 1, IssueGrouping::NOBODY => 1], $grouped->groupTotals);
    }

    public function testPagination(): void
    {
        foreach (range(1, SearchIssuesHandler::PER_PAGE + 3) as $index) {
            $this->builder->issue($this->bugs, $this->client, "Demande {$index}");
        }

        $first = $this->inProject();
        $second = $this->inProject(page: 2);

        self::assertCount(SearchIssuesHandler::PER_PAGE, $first->items);
        self::assertCount(3, $second->items);
        self::assertSame(SearchIssuesHandler::PER_PAGE + 3, $second->total);
        self::assertSame(2, $second->pageCount());
        self::assertSame([], array_intersect(array_map(static fn (Issue $issue) => $issue->getId(), $first->items), array_map(static fn (Issue $issue) => $issue->getId(), $second->items)));
    }

    public function testAPageHoldsTheSizeAsked(): void
    {
        foreach (range(1, 7) as $index) {
            $this->builder->issue($this->bugs, $this->client, "Demande {$index}");
        }

        $page = self::queryBus()->ask(new SearchIssues($this->manager, projectId: $this->project->getId(), perPage: 5));

        self::assertCount(5, $page->items);
        self::assertSame(7, $page->total);
        self::assertSame(2, $page->pageCount());
    }

    public function testRelationsAreLoadedWithTheRows(): void
    {
        $this->builder->issue($this->bugs, $this->client, 'Avec assignée', assignee: $this->nadia);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $issue = $this->inProject()->items[0];

        $unitOfWork = $entityManager->getUnitOfWork();
        foreach ([$issue->getTracker(), $issue->getProject(), $issue->getProject()->getWorkspace(), $issue->getAssignee()] as $related) {
            self::assertNotNull($related);
            self::assertFalse($unitOfWork->isUninitializedObject($related), $related::class.' is fetched with the issue');
        }
    }

    /**
     * The project list as the manager sees it, with the place filter, the sort and the grouping.
     *
     * @param list<string> $place
     *
     * @return Pagination<Issue>
     */
    private function search(array $place = [], ?IssueSort $sort = null, \SortDirection $order = \SortDirection::Ascending, ?IssueGrouping $group = null): Pagination
    {
        return self::queryBus()->ask(new SearchIssues($this->manager, projectId: $this->project->getId(), place: $place, sort: $sort, order: $order, group: $group));
    }

    /**
     * The project list as the manager sees it, unless another viewer is given.
     *
     * @param list<PlaceStatus> $status
     * @param list<int>         $tracker
     * @param list<int>         $assignee
     * @param list<Criticality> $criticality
     *
     * @return Pagination<Issue>
     */
    private function inProject(array $status = [], array $tracker = [], array $assignee = [], array $criticality = [], ?string $q = null, bool $closed = false, int $page = 1, ?User $viewer = null): Pagination
    {
        return self::queryBus()->ask(new SearchIssues($viewer ?? $this->manager, projectId: $this->project->getId(), status: $status, tracker: $tracker, assignee: $assignee, criticality: $criticality, q: $q, closed: $closed, page: $page));
    }

    /**
     * @param list<Issue> $issues
     *
     * @return list<string>
     */
    private static function titles(array $issues): array
    {
        return array_map(static fn (Issue $issue): string => $issue->getTitle(), $issues);
    }
}

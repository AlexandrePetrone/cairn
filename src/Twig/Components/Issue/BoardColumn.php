<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Tracker;
use App\Message\Query\Issue\SearchIssues;
use App\Message\QueryBus;
use App\Repository\Pagination;
use App\Security\AccessControl;
use App\Security\Viewer;
use App\Workflow\PlaceCatalog;
use App\Workflow\WorkflowProvider;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * One column of a kanban: the issues of the tracker at one place, as the
 * viewer sees them and as the board filters them (props the browser
 * cannot change). The page renders it lazily, so a column loads when it
 * shows; it then grows by batches, never the whole board at once. The
 * page folds the closed places: a narrow rail that counts the issues
 * only, unfolded on demand, where a card can still be dropped. Each card says
 * which places the viewer may move it to; Issue:BoardMove moves it, and
 * the `board` controller redraws the two columns concerned.
 */
#[AsLiveComponent('Issue:BoardColumn')]
final class BoardColumn
{
    use DefaultActionTrait;

    /** Issues per batch. */
    public const int BATCH = 20;

    #[LiveProp]
    public Tracker $tracker;

    #[LiveProp]
    public string $placeId;

    /** @var list<string> Criticality values */
    #[LiveProp]
    public array $criticality = [];

    /** @var list<int> user ids, 0 for unassigned */
    #[LiveProp]
    public array $assignees = [];

    /** @var list<int> release ids, 0 for no release */
    #[LiveProp]
    public array $releases = [];

    #[LiveProp]
    public string $q = '';

    /** The batches shown, kept when the column redraws after a move. */
    #[LiveProp(writable: true)]
    public int $batches = 1;

    /** Folded, the column is a rail that counts its issues and loads none. */
    #[LiveProp(writable: true)]
    public bool $open = true;

    /** @var Pagination<Issue>|null read once per render */
    private ?Pagination $issues = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly AccessControl $access,
        private readonly WorkflowProvider $workflows,
    ) {
    }

    public function getPlace(): PlaceDefinition
    {
        return $this->workflows->definition($this->tracker->getWorkflowId())->place($this->placeId);
    }

    /**
     * The issues shown, or, folded, a page of none that still counts them.
     *
     * @return Pagination<Issue>
     */
    public function getIssues(): Pagination
    {
        return $this->issues ??= $this->queries->ask(new SearchIssues(
            viewer: $this->viewer->get(),
            projectId: $this->tracker->getProject()->getId(),
            place: [PlaceCatalog::key($this->tracker->getWorkflowId(), $this->placeId)],
            tracker: [(int) $this->tracker->getId()],
            assignee: $this->assignees,
            criticality: array_values(array_filter(array_map(Criticality::tryFrom(...), $this->criticality))),
            release: $this->releases,
            q: $this->q,
            perPage: $this->open ? min(200, self::BATCH * max(1, $this->batches)) : 1,
        ));
    }

    public function getRemaining(): int
    {
        return $this->open ? max(0, $this->getIssues()->total - \count($this->getIssues()->items)) : 0;
    }

    /**
     * The places the viewer may move the issue to, one transition away.
     *
     * @return list<string>
     */
    public function placesFor(Issue $issue): array
    {
        return array_values(array_unique(array_map(
            static fn (TransitionDefinition $transition): string => $transition->to,
            $this->access->transitionsFor($this->viewer->get(), $issue),
        )));
    }

    #[LiveAction]
    public function more(): void
    {
        $this->batches = min(10, $this->batches + 1);
    }

    #[LiveAction]
    public function toggle(): void
    {
        $this->open = !$this->open;
        $this->batches = 1;
    }
}

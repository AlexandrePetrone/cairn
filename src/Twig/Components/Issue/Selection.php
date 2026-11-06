<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Enum\Criticality;
use App\Entity\Tracker;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\MoveIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\CommandBus;
use App\Message\Query\Issue\ListSelectionActions;
use App\Message\Query\Issue\SelectionActions;
use App\Message\QueryBus;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use App\Workflow\WorkflowProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The bulk actions of a selection: the issues the viewer still sees among
 * those checked, and the actions valid for all of them
 * (ListSelectionActions). An action is one command per issue, each in its
 * own transaction: a refusal on one issue leaves the others done, and the
 * user is told which. Then back to the list.
 */
#[AsLiveComponent('Issue:Selection')]
final class Selection extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    /** @var list<int> */
    #[LiveProp]
    public array $issueIds = [];

    /** Where to go back after the action: the list, as the page resolved it. */
    #[LiveProp]
    public string $back = '/';

    /** The action being prepared: `transition:<id>`, `assign`, `comment`, `criticality`, `release` or `move`. */
    #[LiveProp(writable: true)]
    public ?string $action = null;

    /** The comment posted on every issue. */
    #[LiveProp(writable: true)]
    public string $comment = '';

    #[LiveProp(writable: true)]
    public bool $commentPrivate = false;

    /** The person to assign, null for nobody. */
    #[LiveProp(writable: true)]
    public ?int $assigneeId = null;

    #[LiveProp(writable: true)]
    public string $criticality = Criticality::DEFAULT->value;

    /** The release to schedule the issues in, null for none. */
    #[LiveProp(writable: true)]
    public ?int $releaseId = null;

    /** The tracker and the place of a move. */
    #[LiveProp(writable: true, onUpdated: 'onMoveTrackerChanged')]
    public ?int $moveTrackerId = null;

    #[LiveProp(writable: true)]
    public ?string $movePlaceId = null;

    private ?SelectionActions $selection = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly TranslatorInterface $translator,
        private readonly WorkflowProvider $workflows,
    ) {
    }

    public function getSelection(): SelectionActions
    {
        return $this->selection ??= $this->queries->ask(new ListSelectionActions($this->viewer->get(), $this->issueIds));
    }

    /**
     * The label of the action being prepared.
     */
    public function getActionLabel(): ?string
    {
        if (null === $this->action) {
            return null;
        }

        return $this->getSelection()->transitionFor($this->action)->label ?? $this->translator->trans('issue.bulk.'.$this->action);
    }

    /** The tracker chosen for a move among those offered, else the first; null when none is. */
    public function getMoveTracker(): ?Tracker
    {
        $offered = $this->getSelection()->moveTrackers;

        return array_find($offered, fn (Tracker $tracker): bool => $tracker->getId() === $this->moveTrackerId) ?? $offered[0] ?? null;
    }

    /**
     * @return list<PlaceDefinition>
     */
    public function getMovePlaces(): array
    {
        $tracker = $this->getMoveTracker();

        return null === $tracker ? [] : array_values($this->workflows->definition($tracker->getWorkflowId())->places);
    }

    /**
     * Another tracker, another workflow: the place starts at its
     * beginning. The browser sends the text value of the tracker select as
     * updated on every request: the hook only acts when it really changed.
     */
    public function onMoveTrackerChanged(?int $previous = null): void
    {
        if (null !== $previous && $previous === $this->moveTrackerId) {
            return;
        }
        $tracker = $this->getMoveTracker();
        $this->movePlaceId = null === $tracker ? null : $this->workflows->definition($tracker->getWorkflowId())->initial;
    }

    #[LiveAction]
    public function choose(#[LiveArg] string $choice): void
    {
        if (!$this->getSelection()->offers($choice)) {
            return;
        }
        $this->action = $choice;
        $this->comment = '';
        $this->commentPrivate = false;
        $this->assigneeId = null;
        $this->criticality = Criticality::DEFAULT->value;
        $this->releaseId = null;
        $this->moveTrackerId = $this->getMoveTracker()?->getId();
        $this->onMoveTrackerChanged();
    }

    #[LiveAction]
    public function back(): void
    {
        $this->action = null;
    }

    /**
     * Applies the action to every issue of the selection, as it stands
     * now: the browser's choice is checked again against what the
     * selection offers.
     */
    #[LiveAction]
    public function apply(CommandBus $commands): ?Response
    {
        $selection = $this->getSelection();
        if (null === $this->action || [] === $selection->issues || !$selection->offers($this->action)) {
            return null;
        }

        $actorId = (int) $this->viewer->get()->getId();
        $comment = '' === trim($this->comment) ? null : $this->comment;
        $private = $this->commentPrivate && $selection->canCommentPrivately;

        $transition = $selection->transitionFor($this->action);
        if (null !== $transition) {
            if ($transition->requiresComment && null === $comment) {
                $this->error = $transition->commentPrompt ?? $this->translator->trans('issue.transition.comment_required');

                return null;
            }
            $build = static fn (string $key): object => new ApplyTransition($actorId, $key, $transition->id, $comment, $private);
        } elseif ('comment' === $this->action) {
            if (null === $comment) {
                $this->error = $this->translator->trans('issue.comment.empty');

                return null;
            }
            $build = static fn (string $key): object => new AddComment($actorId, $key, $comment, $private);
        } elseif ('assign' === $this->action) {
            $assigneeId = $this->assigneeId ?? UpdateIssue::NOBODY;
            $build = static fn (string $key): object => new UpdateIssue($actorId, $key, assigneeId: $assigneeId);
        } elseif ('release' === $this->action) {
            $releaseId = $this->releaseId ?? UpdateIssue::NOBODY;
            $build = static fn (string $key): object => new UpdateIssue($actorId, $key, releaseId: $releaseId);
        } elseif ('move' === $this->action) {
            $tracker = $this->getMoveTracker();
            $placeId = $this->movePlaceId;
            if (null === $tracker || null === $placeId) {
                return null;
            }
            $trackerId = (int) $tracker->getId();
            $build = static fn (string $key): object => new MoveIssue($actorId, $key, $trackerId, $placeId);
        } else {
            $criticality = Criticality::tryFrom($this->criticality);
            if (null === $criticality) {
                return null;
            }
            $build = static fn (string $key): object => new UpdateIssue($actorId, $key, criticality: $criticality);
        }

        $done = 0;
        $refused = [];
        $reason = null;
        foreach ($selection->issues as $issue) {
            $refusal = $this->refusal($commands, $build($issue->getKey()));
            if (null === $refusal) {
                ++$done;
            } else {
                $refused[] = $issue->getKey();
                $reason ??= $refusal;
            }
        }

        if (0 === $done) {
            $this->error = $this->translator->trans('issue.bulk.all_refused', ['%reason%' => $reason]);
            $this->selection = null;

            return null;
        }

        $this->addFlash('success', [] === $refused
            ? $this->translator->trans('issue.bulk.done', ['%count%' => $done])
            : $this->translator->trans('issue.bulk.partly_done', ['%count%' => $done, '%refused%' => implode(', ', $refused), '%reason%' => $reason]));

        return $this->redirect($this->back);
    }
}

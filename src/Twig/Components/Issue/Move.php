<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Entity\Issue;
use App\Entity\Tracker;
use App\Message\Command\Issue\MoveIssue;
use App\Message\CommandBus;
use App\Repository\TrackerRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use App\Workflow\WorkflowProvider;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The « ⋯ » menu of the issue page, for whoever may take the issue out of
 * its workflow: « Move… » opens a dialog choosing a tracker of the project
 * and a place of its workflow. The current tracker is offered with
 * `issue.force_place`, the others with `issue.change_tracker`; nothing
 * shows to someone holding neither. The dialog warns when no action leads
 * from the current place to the chosen one.
 */
#[AsLiveComponent('Issue:Move')]
final class Move
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public Issue $issue;

    #[LiveProp]
    public bool $open = false;

    #[LiveProp(writable: true, onUpdated: 'onTrackerChanged')]
    public ?int $trackerId = null;

    #[LiveProp(writable: true)]
    public ?string $placeId = null;

    /**
     * The trackers offered, read once per request.
     *
     * @var list<Tracker>|null
     */
    private ?array $offered = null;

    public function __construct(
        private readonly TrackerRepository $trackers,
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly WorkflowProvider $workflows,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function canForcePlace(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueForcePlace, $this->issue->getProject());
    }

    public function canChangeTracker(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueChangeTracker, $this->issue->getProject());
    }

    public function canMove(): bool
    {
        return [] !== $this->getTrackers();
    }

    /**
     * The trackers the issue may be moved to: its own with `force_place`
     * (even deactivated, it keeps its issues), the other active ones of
     * the project with `change_tracker`, private ones for internal users.
     *
     * @return list<Tracker>
     */
    public function getTrackers(): array
    {
        if (null !== $this->offered) {
            return $this->offered;
        }

        $current = $this->issue->getTracker();
        $offered = $this->canForcePlace() ? [$current] : [];
        if ($this->canChangeTracker()) {
            foreach ($this->trackers->findByProject($this->issue->getProject(), includePrivate: $this->viewer->get()->isInternal()) as $tracker) {
                if ($tracker->getId() !== $current->getId()) {
                    $offered[] = $tracker;
                }
            }
        }

        return $this->offered = $offered;
    }

    /** The chosen tracker among those offered, else the first; null when none is. */
    public function getTracker(): ?Tracker
    {
        $offered = $this->getTrackers();

        return array_find($offered, fn (Tracker $tracker): bool => $tracker->getId() === $this->trackerId) ?? $offered[0] ?? null;
    }

    public function isSameTracker(): bool
    {
        return $this->getTracker()?->getId() === $this->issue->getTracker()->getId();
    }

    /**
     * @return list<PlaceDefinition>
     */
    public function getPlaces(): array
    {
        $tracker = $this->getTracker();

        return null === $tracker ? [] : array_values($this->workflows->definition($tracker->getWorkflowId())->places);
    }

    public function getPlaceLabel(): string
    {
        $place = array_find($this->getPlaces(), fn (PlaceDefinition $place): bool => $place->id === $this->placeId);

        return $place->label ?? (string) $this->placeId;
    }

    /**
     * Whether the move stays in the workflow but takes a path it does not
     * draw: no action leads from the current place to the chosen one.
     */
    public function isOffPath(): bool
    {
        if (!$this->isSameTracker() || null === $this->placeId || $this->placeId === $this->issue->getPlace()) {
            return false;
        }
        $workflow = $this->workflows->definition($this->issue->getTracker()->getWorkflowId());

        return null === array_find($workflow->transitionsFrom($this->issue->getPlace()), fn (TransitionDefinition $transition): bool => $transition->to === $this->placeId);
    }

    #[LiveAction]
    public function open(): void
    {
        $this->open = $this->canMove();
        $this->trackerId = $this->getTracker()?->getId();
        $this->onTrackerChanged();
        $this->error = null;
    }

    #[LiveAction]
    public function cancel(): void
    {
        $this->open = false;
        $this->error = null;
    }

    #[LiveAction]
    public function confirm(CommandBus $commands): void
    {
        $tracker = $this->getTracker();
        if (null === $tracker || null === $this->placeId) {
            $this->cancel();

            return;
        }
        $label = $this->getPlaceLabel();
        $command = new MoveIssue((int) $this->viewer->get()->getId(), $this->issue->getKey(), $this->isSameTracker() ? null : (int) $tracker->getId(), $this->placeId);
        if (!$this->tryCommand($commands, $command)) {
            return;
        }

        $this->open = false;
        $this->announce($this->translator->trans('issue.move.done', ['%key%' => $this->issue->getKey(), '%place%' => $label]));
    }

    /**
     * The same tracker starts from the current place, another one from the
     * beginning of its workflow; so does a place the workflow no longer has.
     * The browser sends the text value of the tracker select as updated on
     * every request: the hook only acts when the tracker really changed.
     */
    public function onTrackerChanged(?int $previous = null): void
    {
        if (null !== $previous && $previous === $this->trackerId) {
            return;
        }
        $tracker = $this->getTracker();
        if (null === $tracker) {
            $this->placeId = null;

            return;
        }
        $workflow = $this->workflows->definition($tracker->getWorkflowId());
        $this->placeId = $this->isSameTracker() && $workflow->hasPlace($this->issue->getPlace()) ? $this->issue->getPlace() : $workflow->initial;
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }
}

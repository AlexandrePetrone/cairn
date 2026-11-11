<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Release;
use App\Entity\Tracker;
use App\Entity\User;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\ChangeTracker;
use App\Message\Command\Issue\MoveIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\Query\Project\ListTrackers;
use App\Message\QueryBus;
use App\Repository\ReleaseRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\PlaceMove;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use App\Workflow\WorkflowProvider;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The properties of an issue in the side panel of its page: step,
 * assignee, criticality, release and visibility read as values and, for
 * whoever may change them, open a menu of the choices; the release shows
 * once the project has releases.
 * The step is a menu for the agency only: the places of the workflow the
 * viewer may take the issue to (`AccessControl::movesFor()`: by an action
 * when one leads there, outside the workflow otherwise) and, last, the
 * other trackers of the project, each with the places of its workflow.
 * External users act from the response box, where an action is explained.
 * A change is its own command; the other components of the page are told
 * (`issue:changed`), and this one follows their changes.
 */
#[AsLiveComponent('Issue:Properties')]
final class Properties
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public Issue $issue;

    /** @var list<PlaceMove>|null read once per render, forgotten when the issue moves */
    private ?array $moves = null;

    /** @var list<Tracker>|null read once per request: the trackers of the project do not change with the issue */
    private ?array $otherTrackers = null;

    public function __construct(
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly QueryBus $queries,
        private readonly ReleaseRepository $releases,
        private readonly WorkflowProvider $workflows,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Whether the step is a menu: for the agency, when it leads somewhere.
     */
    public function canChangePlace(): bool
    {
        return $this->viewer->get()->isInternal() && ([] !== $this->getMoves() || [] !== $this->getOtherTrackers());
    }

    /**
     * @return list<PlaceMove>
     */
    public function getMoves(): array
    {
        return $this->moves ??= $this->access->movesFor($this->viewer->get(), $this->issue);
    }

    /**
     * The trackers the issue may change to (`issue.change_tracker`): the
     * other active ones of the project the viewer sees.
     *
     * @return list<Tracker>
     */
    public function getOtherTrackers(): array
    {
        if (null !== $this->otherTrackers) {
            return $this->otherTrackers;
        }
        if (!$this->access->isGranted($this->viewer->get(), Permission::IssueChangeTracker, $this->issue->getProject())) {
            return $this->otherTrackers = [];
        }
        $current = $this->issue->getTracker()->getId();

        return $this->otherTrackers = array_values(array_filter(
            $this->queries->ask(new ListTrackers($this->viewer->get(), $this->issue->getProject())),
            static fn (Tracker $tracker): bool => $tracker->getId() !== $current,
        ));
    }

    /**
     * The places of a tracker's workflow, in its order; of the issue's own
     * tracker by default.
     *
     * @return list<PlaceDefinition>
     */
    public function placesOf(?Tracker $tracker = null): array
    {
        return array_values($this->workflows->definition(($tracker ?? $this->issue->getTracker())->getWorkflowId())->places);
    }

    /**
     * Redraws with the issue as another component left it.
     */
    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }

    public function canAssign(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueAssign, $this->issue->getProject());
    }

    public function canSetCriticality(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueSetCriticality, $this->issue->getProject());
    }

    public function canSetRelease(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueSetRelease, $this->issue->getProject());
    }

    public function hasReleases(): bool
    {
        return $this->releases->hasReleases($this->issue->getProject());
    }

    /**
     * The releases the issue can go to: the open ones, and the one it is
     * in even when delivered or abandoned, so that the choice shows it.
     *
     * @return list<Release>
     */
    public function getReleaseChoices(): array
    {
        $choices = $this->releases->findByProject($this->issue->getProject(), openOnly: true);
        $current = $this->issue->getRelease();
        if (null !== $current && !$current->isOpen()) {
            $choices[] = $current;
        }

        return $choices;
    }

    /**
     * An issue of a private tracker has no visibility to choose.
     */
    public function canSetVisibility(): bool
    {
        return !$this->issue->getTracker()->isPrivate()
            && $this->access->isGranted($this->viewer->get(), Permission::IssueSetPrivate, $this->issue->getProject());
    }

    /**
     * @return list<User>
     */
    public function getMembers(): array
    {
        return $this->queries->ask(new ListProjectMembers($this->issue->getProject()));
    }

    /**
     * Applies the choice made in the menu of a property: an id or nothing
     * for the assignee and the release, a level, `public` or `private`.
     */
    #[LiveAction]
    public function change(CommandBus $commands, #[LiveArg] string $property, #[LiveArg] string $value): void
    {
        $actorId = (int) $this->viewer->get()->getId();
        $key = $this->issue->getKey();
        $criticality = Criticality::tryFrom($value);
        $command = match ($property) {
            'assignee' => new UpdateIssue($actorId, $key, assigneeId: self::idOrNobody($value)),
            'criticality' => null === $criticality ? null : new UpdateIssue($actorId, $key, criticality: $criticality),
            'release' => new UpdateIssue($actorId, $key, releaseId: self::idOrNobody($value)),
            'visibility' => new UpdateIssue($actorId, $key, private: 'private' === $value),
            default => null,
        };
        if (null === $command) {
            return;
        }

        if (!$this->tryCommand($commands, $command)) {
            return;
        }

        $this->announce($this->translator->trans('issue.action.done', ['%key%' => $key]));
    }

    /**
     * Applies an action of the workflow chosen in the step menu.
     */
    #[LiveAction]
    public function transition(CommandBus $commands, #[LiveArg] string $id): void
    {
        $transition = array_find($this->getMoves(), static fn (PlaceMove $move): bool => $move->transition?->id === $id)?->transition;
        if (null === $transition || !$this->canChangePlace()) {
            $this->error = $this->translator->trans('issue.action.refused');

            return;
        }

        if ($this->tryCommand($commands, new ApplyTransition((int) $this->viewer->get()->getId(), $this->issue->getKey(), $transition->id))) {
            $this->moves = null;
            $this->announce($this->translator->trans('issue.transition.applied', ['%key%' => $this->issue->getKey(), '%transition%' => $transition->label]));
        }
    }

    /**
     * Takes the issue to a place of its workflow (MoveIssue decides how:
     * the workflow first), or to another tracker on a place of its workflow.
     */
    #[LiveAction]
    public function move(CommandBus $commands, #[LiveArg] string $place, #[LiveArg] ?int $tracker = null): void
    {
        // The browser's choice is checked again against what the menu offers.
        $offered = null === $tracker
            ? array_any($this->getMoves(), static fn (PlaceMove $move): bool => $move->place->id === $place)
            : array_any($this->getOtherTrackers(), static fn (Tracker $other): bool => $other->getId() === $tracker);
        if (!$offered || !$this->canChangePlace()) {
            $this->error = $this->translator->trans('issue.action.refused');

            return;
        }

        $actorId = (int) $this->viewer->get()->getId();
        $command = null === $tracker ? new MoveIssue($actorId, $this->issue->getKey(), $place) : new ChangeTracker($actorId, $this->issue->getKey(), $tracker, $place);
        if ($this->tryCommand($commands, $command)) {
            $this->moves = null;
            $this->otherTrackers = null;
            $this->announce($this->translator->trans('issue.move.done', ['%key%' => $this->issue->getKey(), '%place%' => $this->workflows->placeOf($this->issue)->label ?? $place]));
        }
    }

    private static function idOrNobody(string $value): int
    {
        return '' === $value ? UpdateIssue::NOBODY : (int) $value;
    }
}

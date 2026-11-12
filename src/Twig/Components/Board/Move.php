<?php

declare(strict_types=1);

namespace App\Twig\Components\Board;

use App\Config\Definition\TransitionDefinition;
use App\Entity\Issue;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\MoveIssue;
use App\Message\Command\Issue\RankIssue;
use App\Message\CommandBus;
use App\Repository\IssueRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\PlaceMove;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Moves a card of the kanban, the only component of the board that acts:
 * the `board` controller calls `move` when a card is dropped on another
 * column, where it stays in the meantime. How the viewer may take the
 * issue to that place is looked up again here (AccessControl::movesFor()):
 * with nothing to ask, MoveIssue takes it there, by the action that leads
 * to the place or outside the workflow; otherwise a dialog asks for the
 * comment an action requires, or which of several actions. Once the move is settled the
 * browser is told (`board:moved`): applied, with the places the card may
 * now go to, or not, and the controller puts the card back. No column is
 * read again.
 * A card is dropped between two others: the move done, RankIssue places
 * it there for whoever holds `issue.rank`; otherwise it stands on top of
 * its new column, as every issue that arrives, and the browser is told
 * (`top`). Dropped within its own column, `rank` only ranks it.
 */
#[AsLiveComponent('Board:Move')]
final class Move
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    /** The issue whose move waits in the dialog, null when there is none. */
    #[LiveProp]
    public ?string $issueKey = null;

    /** The place it was dropped on. */
    #[LiveProp]
    public ?string $place = null;

    /** The cards it was dropped between, kept while the dialog asks. */
    #[LiveProp]
    public ?string $above = null;

    #[LiveProp]
    public ?string $below = null;

    #[LiveProp(writable: true)]
    public string $transitionId = '';

    #[LiveProp(writable: true)]
    public string $comment = '';

    #[LiveProp(writable: true)]
    public bool $commentPrivate = false;

    public function __construct(
        private readonly IssueRepository $issues,
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[LiveAction]
    public function move(CommandBus $commands, #[LiveArg] string $key, #[LiveArg] string $place, #[LiveArg] ?string $above = null, #[LiveArg] ?string $below = null): void
    {
        $this->above = $above;
        $this->below = $below;
        $issue = $this->visibleIssue($key);
        $moves = null === $issue ? [] : $this->movesTo($issue, $place);
        if (null === $issue || [] === $moves) {
            $this->settle($key);
            $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('issue.board.not_allowed')]);

            return;
        }
        $transition = $moves[0]->transition;
        if (1 === \count($moves) && (null === $transition || !$this->isCommentRequired($transition))) {
            // Nothing to ask: MoveIssue takes the action when there is one, forces the place otherwise.
            $done = null === $transition
                ? $this->translator->trans('issue.move.done', ['%key%' => $issue->getKey(), '%place%' => $moves[0]->place->label])
                : $this->translator->trans('issue.transition.applied', ['%key%' => $issue->getKey(), '%transition%' => $transition->label]);
            $this->settled($commands, $issue, $this->refusal($commands, new MoveIssue((int) $this->viewer->get()->getId(), $issue->getKey(), $place)), $done);

            return;
        }

        $this->issueKey = $issue->getKey();
        $this->place = $place;
        $this->transitionId = (string) $transition?->id;
        $this->comment = '';
        $this->commentPrivate = false;
        $this->error = null;
    }

    /**
     * Places a card dropped within its own column between the two it was
     * dropped between. Nothing is announced: the card is where it was put,
     * or goes back there.
     */
    #[LiveAction]
    public function rank(CommandBus $commands, #[LiveArg] string $key, #[LiveArg] ?string $above = null, #[LiveArg] ?string $below = null): void
    {
        $issue = $this->visibleIssue($key);
        $refusal = null === $issue
            ? $this->translator->trans('issue.action.refused')
            : $this->refusal($commands, new RankIssue((int) $this->viewer->get()->getId(), $issue->getKey(), $above, $below));
        if (null !== $refusal) {
            $this->settle($key);
            $this->dispatchBrowserEvent('toast:show', ['message' => $refusal]);

            return;
        }
        $this->settle($key, $issue);
    }

    /**
     * Applies the transition chosen in the dialog, with its comment.
     */
    #[LiveAction]
    public function confirm(CommandBus $commands): void
    {
        $issue = null === $this->issueKey ? null : $this->visibleIssue($this->issueKey);
        $transition = null === $issue || null === $this->place ? null : array_find($this->transitionsTo($issue, $this->place), fn (TransitionDefinition $transition): bool => $transition->id === $this->transitionId);
        if (null === $issue || null === $transition) {
            $this->cancel();

            return;
        }
        $comment = '' === trim($this->comment) ? null : $this->comment;
        if ($this->isCommentRequired($transition) && null === $comment) {
            $this->error = $transition->commentPrompt ?? $this->translator->trans('issue.transition.comment_required');

            return;
        }

        $this->apply($commands, $issue, $transition, $comment, $this->commentPrivate && $this->canCommentPrivately($issue));
    }

    /**
     * Closes the dialog; the card goes back to its column.
     */
    #[LiveAction]
    public function cancel(): void
    {
        if (null !== $this->issueKey) {
            $this->settle($this->issueKey);
        }
        $this->close();
    }

    public function getIssue(): ?Issue
    {
        return null === $this->issueKey ? null : $this->visibleIssue($this->issueKey);
    }

    /**
     * The actions offered in the dialog.
     *
     * @return list<TransitionDefinition>
     */
    public function getTransitions(): array
    {
        $issue = $this->getIssue();

        return null === $issue || null === $this->place ? [] : $this->transitionsTo($issue, $this->place);
    }

    public function getChosen(): ?TransitionDefinition
    {
        return array_find($this->getTransitions(), fn (TransitionDefinition $transition): bool => $transition->id === $this->transitionId);
    }

    public function isCommentRequired(TransitionDefinition $transition): bool
    {
        return $this->access->commentRequired($this->viewer->get(), $transition);
    }

    public function canCommentPrivately(Issue $issue): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::CommentSetPrivate, $issue->getProject());
    }

    private function apply(CommandBus $commands, Issue $issue, TransitionDefinition $transition, ?string $comment, bool $private): void
    {
        $refusal = $this->refusal($commands, new ApplyTransition((int) $this->viewer->get()->getId(), $issue->getKey(), $transition->id, $comment, $private));
        $this->settled($commands, $issue, $refusal, $this->translator->trans('issue.transition.applied', ['%key%' => $issue->getKey(), '%transition%' => $transition->label]));
    }

    /**
     * Ends a move the command bus answered: done, it is announced; refused,
     * the dialog, when it is open, keeps the card where it was dropped and
     * says why, otherwise the card goes back and a toast says why.
     */
    private function settled(CommandBus $commands, Issue $issue, ?string $refusal, string $done): void
    {
        if (null === $refusal) {
            $this->settle($issue->getKey(), $issue, top: !$this->ranked($commands, $issue));
            $this->close();
            $this->announce($done);

            return;
        }
        if (null === $this->issueKey) {
            $this->settle($issue->getKey());
            $this->dispatchBrowserEvent('toast:show', ['message' => $refusal]);

            return;
        }
        $this->error = $refusal;
    }

    /**
     * Tells the browser how the move of a card ended: applied, with the
     * places the viewer may now move the issue to from where it stands
     * (and those among them outside the workflow), or not, and the card
     * goes back to its column. `top` says the card was not placed where it
     * was dropped: it stands on top of its column.
     */
    private function settle(string $key, ?Issue $moved = null, bool $top = false): void
    {
        $moves = null === $moved ? [] : $this->access->movesFor($this->viewer->get(), $moved);
        $this->dispatchBrowserEvent('board:moved', [
            'key' => $key,
            'applied' => null !== $moved,
            'top' => $top,
            'places' => PlaceMove::placeIds($moves),
            'forced' => PlaceMove::placeIds($moves, forcedOnly: true),
        ]);
    }

    /**
     * Places the issue just moved between the cards it was dropped
     * between, when the viewer may and there are any; otherwise it stays
     * on top of its column, where it arrived.
     */
    private function ranked(CommandBus $commands, Issue $issue): bool
    {
        if ((null === $this->above && null === $this->below) || !$this->access->isGranted($this->viewer->get(), Permission::IssueRank, $issue->getProject())) {
            return false;
        }

        return null === $this->refusal($commands, new RankIssue((int) $this->viewer->get()->getId(), $issue->getKey(), $this->above, $this->below));
    }

    private function close(): void
    {
        $this->issueKey = null;
        $this->place = null;
        $this->above = null;
        $this->below = null;
        $this->transitionId = '';
        $this->comment = '';
        $this->commentPrivate = false;
        $this->error = null;
    }

    /**
     * How the viewer may take the issue to the place: one move per action
     * that leads there, or a single one outside the workflow.
     *
     * @return list<PlaceMove>
     */
    private function movesTo(Issue $issue, string $place): array
    {
        return array_values(array_filter(
            $this->access->movesFor($this->viewer->get(), $issue),
            static fn (PlaceMove $move): bool => $move->place->id === $place,
        ));
    }

    /**
     * The actions that lead the issue to the place.
     *
     * @return list<TransitionDefinition>
     */
    private function transitionsTo(Issue $issue, string $place): array
    {
        return array_values(array_filter(array_map(static fn (PlaceMove $move): ?TransitionDefinition => $move->transition, $this->movesTo($issue, $place))));
    }

    private function visibleIssue(string $key): ?Issue
    {
        $issue = $this->issues->findOneByKey($key);

        return null !== $issue && $this->access->canSee($this->viewer->get(), $issue) ? $issue : null;
    }
}

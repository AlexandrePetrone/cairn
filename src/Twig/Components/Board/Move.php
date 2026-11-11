<?php

declare(strict_types=1);

namespace App\Twig\Components\Board;

use App\Config\Definition\TransitionDefinition;
use App\Entity\Issue;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\CommandBus;
use App\Repository\IssueRepository;
use App\Security\AccessControl;
use App\Security\Permission;
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
 * column, where it stays in the meantime. The transitions the viewer may
 * apply from the issue's place to that place are looked up again here; one
 * that needs no comment applies at once, otherwise a dialog asks for the
 * comment, or which of several actions. Once the move is settled the
 * browser is told (`board:moved`): applied, with the places the card may
 * now go to, or not, and the controller puts the card back. No column is
 * read again.
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
    public function move(CommandBus $commands, #[LiveArg] string $key, #[LiveArg] string $place): void
    {
        $issue = $this->visibleIssue($key);
        $transitions = null === $issue ? [] : $this->transitionsTo($issue, $place);
        if (null === $issue || [] === $transitions) {
            $this->settle($key);
            $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('issue.board.not_allowed')]);

            return;
        }

        if (1 === \count($transitions) && !$this->isCommentRequired($transitions[0])) {
            $this->apply($commands, $issue, $transitions[0], null, false);

            return;
        }

        $this->issueKey = $issue->getKey();
        $this->place = $place;
        $this->transitionId = $transitions[0]->id;
        $this->comment = '';
        $this->commentPrivate = false;
        $this->error = null;
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
        if (!$this->tryCommand($commands, new ApplyTransition((int) $this->viewer->get()->getId(), $issue->getKey(), $transition->id, $comment, $private))) {
            // Refused: the dialog, when it is open, keeps the card where it was dropped and says why.
            if (null === $this->issueKey) {
                $this->settle($issue->getKey());
                $this->dispatchBrowserEvent('toast:show', ['message' => $this->error]);
            }

            return;
        }
        $this->settle($issue->getKey(), $issue);
        $this->close();
        $this->announce($this->translator->trans('issue.transition.applied', ['%key%' => $issue->getKey(), '%transition%' => $transition->label]));
    }

    /**
     * Tells the browser how the move of a card ended: applied, with the
     * places the viewer may now move the issue to from where it stands, or
     * not, and the card goes back to its column.
     */
    private function settle(string $key, ?Issue $moved = null): void
    {
        $this->dispatchBrowserEvent('board:moved', [
            'key' => $key,
            'applied' => null !== $moved,
            'places' => null === $moved ? [] : $this->access->reachablePlaces($this->viewer->get(), $moved),
        ]);
    }

    private function close(): void
    {
        $this->issueKey = null;
        $this->place = null;
        $this->transitionId = '';
        $this->comment = '';
        $this->commentPrivate = false;
        $this->error = null;
    }

    /**
     * @return list<TransitionDefinition>
     */
    private function transitionsTo(Issue $issue, string $place): array
    {
        return array_values(array_filter(
            $this->access->transitionsFor($this->viewer->get(), $issue),
            static fn (TransitionDefinition $transition): bool => $transition->to === $place,
        ));
    }

    private function visibleIssue(string $key): ?Issue
    {
        $issue = $this->issues->findOneByKey($key);

        return null !== $issue && $this->access->canSee($this->viewer->get(), $issue) ? $issue : null;
    }
}

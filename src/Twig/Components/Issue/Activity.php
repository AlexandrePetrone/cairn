<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\TransitionDefinition;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Message\Command\Attachment\RemoveAttachment;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\DeleteComment;
use App\Message\Command\Issue\EditComment;
use App\Message\CommandBus;
use App\Message\Query\Issue\IssueDetail;
use App\Message\Query\Issue\ShowIssue;
use App\Message\QueryBus;
use App\Repository\AttachmentRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use App\Twig\Components\HandlesAttachments;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Thread and response box of the issue page. The box asks what to do:
 * one of the workflow actions of the viewer's role, or a comment alone,
 * nothing chosen in advance; one button sends the comment with the
 * choice. Without any action, the box is the comment editor alone. A
 * success redraws the thread in place, tells the other components of the
 * page (`issue:changed`) and shows a toast; the comment being written
 * survives what the other components do.
 */
#[AsLiveComponent('Issue:Activity')]
final class Activity
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;
    use HandlesAttachments;

    #[LiveProp]
    public Issue $issue;

    /** The comment being written; not `comment`, which canComment() would read. */
    #[LiveProp(writable: true)]
    public string $body = '';

    #[LiveProp(writable: true)]
    public bool $private = false;

    /** What the response does: `comment`, or the id of a transition; null until chosen. */
    #[LiveProp(writable: true)]
    public ?string $outcome = null;

    /** @var list<string> uuids of the files sent for the comment being written, pending until it is posted */
    #[LiveProp]
    public array $pending = [];

    /** The comment being rewritten, and its new body. */
    #[LiveProp(writable: true)]
    public ?int $editingCommentId = null;

    #[LiveProp(writable: true)]
    public string $commentBody = '';

    /** @var list<string> uuids of the files sent while rewriting a comment */
    #[LiveProp]
    public array $editPending = [];

    /** The comment whose deletion waits for a confirmation. */
    #[LiveProp(writable: true)]
    public ?int $deletingCommentId = null;

    /** The detail, read once per request; forgotten after an action changed the issue. */
    private ?IssueDetail $detail = null;

    public function __construct(
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly QueryBus $queries,
        private readonly TranslatorInterface $translator,
        private readonly AttachmentRepository $attachments,
    ) {
    }

    /**
     * The files sent for the comment being written, still pending.
     *
     * @return list<Attachment>
     */
    public function getPendingFiles(): array
    {
        return $this->pendingFiles($this->pending);
    }

    /**
     * The files sent while rewriting a comment, still pending.
     *
     * @return list<Attachment>
     */
    public function getEditPendingFiles(): array
    {
        return $this->pendingFiles($this->editPending);
    }

    /**
     * @param list<string> $uuids
     *
     * @return list<Attachment>
     */
    private function pendingFiles(array $uuids): array
    {
        if ([] === $uuids) {
            return [];
        }

        return $this->attachments->findUnattached($this->issue->getProject(), $this->viewer->get(), array_map(Uuid::fromString(...), $uuids));
    }

    public function getDetail(): IssueDetail
    {
        return $this->detail ??= $this->queries->ask(new ShowIssue($this->viewer->get(), (int) $this->issue->getProject()->getId(), $this->issue->getNumber()))
            ?? throw new NotFoundHttpException('The issue is no longer visible.');
    }

    /**
     * @return list<TransitionDefinition>
     */
    public function getTransitions(): array
    {
        return $this->access->transitionsFor($this->viewer->get(), $this->issue);
    }

    public function isCommentRequired(TransitionDefinition $transition): bool
    {
        return $this->access->commentRequired($this->viewer->get(), $transition);
    }

    /** The transition chosen as the outcome, if it is one the viewer may apply. */
    public function getChosenTransition(): ?TransitionDefinition
    {
        return array_find($this->getTransitions(), fn (TransitionDefinition $transition): bool => $transition->id === $this->outcome);
    }

    public function canComment(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::CommentCreate, $this->issue->getProject());
    }

    /**
     * The comments of the thread the viewer may edit or delete, with their
     * files: their own, or all of them with `comment.edit`.
     *
     * @return list<int> comment ids
     */
    public function getEditableComments(): array
    {
        $viewer = $this->viewer->get();
        $ids = [];
        foreach ($this->getDetail()->timeline as $entry) {
            if (null !== $entry->comment && $this->access->canEditComment($viewer, $entry->comment)) {
                $ids[] = (int) $entry->comment->getId();
            }
        }

        return $ids;
    }

    public function canMakePrivate(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::CommentSetPrivate, $this->issue->getProject());
    }

    /**
     * Sends the response: a comment alone, or the chosen transition with
     * the comment as its own. The choice is implicit when there is none to
     * make.
     */
    #[LiveAction]
    public function submit(CommandBus $commands): void
    {
        $transitions = $this->getTransitions();
        $outcome = [] === $transitions ? 'comment' : $this->outcome;
        if (null === $outcome) {
            $this->error = $this->translator->trans('issue.respond.choose');

            return;
        }
        if ('comment' === $outcome) {
            $this->postComment($commands);

            return;
        }

        $transition = $this->getChosenTransition();
        if (null === $transition) {
            $this->error = $this->translator->trans('issue.action.refused');

            return;
        }
        if ($this->isCommentRequired($transition) && '' === trim($this->body)) {
            $this->error = $transition->commentPrompt ?? $this->translator->trans('issue.transition.comment_required');

            return;
        }

        $command = new ApplyTransition((int) $this->viewer->get()->getId(), $this->issue->getKey(), $transition->id, '' === trim($this->body) ? null : $this->body, $this->private && $this->canMakePrivate(), $this->pending);
        if ($this->tryCommand($commands, $command)) {
            $this->posted($this->translator->trans('issue.transition.applied', ['%key%' => $this->issue->getKey(), '%transition%' => $transition->label]));
        }
    }

    private function postComment(CommandBus $commands): void
    {
        if (!$this->canComment()) {
            $this->error = $this->translator->trans('issue.action.refused');

            return;
        }
        if ('' === trim($this->body)) {
            $this->error = $this->translator->trans('issue.comment.empty');

            return;
        }

        if ($this->tryCommand($commands, new AddComment((int) $this->viewer->get()->getId(), $this->issue->getKey(), $this->body, $this->private && $this->canMakePrivate(), $this->pending))) {
            $this->posted($this->translator->trans('issue.comment.added'));
        }
    }

    /**
     * Files chosen for the comment being written, or rewritten: sent now,
     * pending until the comment is posted or saved.
     */
    #[LiveAction]
    public function attach(Request $request, CommandBus $commands): void
    {
        [$sent, $this->error] = $this->sendFiles($request, $commands, $this->viewer->get(), $this->issue->getProject());
        foreach ($sent as $attachment) {
            if (null === $this->editingCommentId) {
                $this->pending[] = $attachment->getUuid()->toRfc4122();
            } else {
                $this->editPending[] = $attachment->getUuid()->toRfc4122();
            }
        }
    }

    /** Takes a file back from the comment being written or rewritten; it stays pending, unused. */
    #[LiveAction]
    public function discard(#[LiveArg] string $uuid): void
    {
        $this->pending = array_values(array_diff($this->pending, [$uuid]));
        $this->editPending = array_values(array_diff($this->editPending, [$uuid]));
    }

    #[LiveAction]
    public function editComment(#[LiveArg] int $id): void
    {
        $comment = $this->editableComment($id);
        if (null === $comment) {
            return;
        }
        $this->editingCommentId = $id;
        $this->commentBody = $comment->getBody();
        $this->editPending = [];
        $this->deletingCommentId = null;
    }

    #[LiveAction]
    public function cancelComment(): void
    {
        $this->editingCommentId = null;
        $this->editPending = [];
    }

    #[LiveAction]
    public function saveComment(CommandBus $commands): void
    {
        if (null === $this->editingCommentId) {
            return;
        }
        if ('' === trim($this->commentBody)) {
            $this->error = $this->translator->trans('issue.comment.empty');

            return;
        }

        if (!$this->tryCommand($commands, new EditComment((int) $this->viewer->get()->getId(), $this->editingCommentId, $this->commentBody, $this->editPending))) {
            return;
        }

        $this->detail = null;
        $this->editingCommentId = null;
        $this->editPending = [];
        $this->announce($this->translator->trans('issue.comment.updated'));
    }

    #[LiveAction]
    public function confirmDelete(#[LiveArg] int $id): void
    {
        $this->deletingCommentId = null === $this->editableComment($id) ? null : $id;
    }

    #[LiveAction]
    public function cancelDelete(): void
    {
        $this->deletingCommentId = null;
    }

    #[LiveAction]
    public function deleteComment(CommandBus $commands): void
    {
        if (null === $this->deletingCommentId) {
            return;
        }

        if (!$this->tryCommand($commands, new DeleteComment((int) $this->viewer->get()->getId(), $this->deletingCommentId))) {
            return;
        }

        $this->detail = null;
        if ($this->editingCommentId === $this->deletingCommentId) {
            $this->cancelComment();
        }
        $this->deletingCommentId = null;
        $this->announce($this->translator->trans('issue.comment.deleted'));
    }

    /** Removes a file of a posted comment. */
    #[LiveAction]
    public function removeAttachment(CommandBus $commands, #[LiveArg] string $uuid, #[LiveArg] string $name): void
    {
        if ($this->tryCommand($commands, new RemoveAttachment((int) $this->viewer->get()->getId(), $uuid))) {
            $this->detail = null;
            $this->announce($this->translator->trans('attachment.removed', ['%name%' => $name]));
        }
    }

    /**
     * Redraws with the issue as another component left it; the comment
     * being written is kept.
     */
    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }

    /** A comment of the thread the viewer may edit, null otherwise. */
    private function editableComment(int $id): ?Comment
    {
        foreach ($this->getDetail()->timeline as $entry) {
            if ($entry->comment?->getId() === $id && $this->access->canEditComment($this->viewer->get(), $entry->comment)) {
                return $entry->comment;
            }
        }

        return null;
    }

    /** The comment being written went through: the composer starts afresh. */
    private function posted(string $message): void
    {
        $this->detail = null;
        $this->body = '';
        $this->private = false;
        $this->pending = [];
        $this->outcome = null;
        // The editor sits outside the morph (data-live-ignore): it is told to empty itself.
        $this->dispatchBrowserEvent('editor:reset');
        $this->announce($message);
    }
}

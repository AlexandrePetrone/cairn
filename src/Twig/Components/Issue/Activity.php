<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\TransitionDefinition;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\DeleteComment;
use App\Message\Command\Issue\EditComment;
use App\Message\CommandBus;
use App\Message\Query\Issue\IssueDetail;
use App\Message\Query\Issue\ShowIssue;
use App\Message\QueryBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Repository\AttachmentRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Twig\Components\HandlesAttachments;
use App\Workflow\WorkflowProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
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
 * Thread and comment editor of the issue page: comment, and apply the
 * workflow actions of the viewer's role, the comment going with the
 * action. A success redraws the thread in place, tells the other
 * components of the page (`issue:changed`) and shows a toast; the comment
 * being written survives what the other components do.
 */
#[AsLiveComponent('Issue:Activity')]
final class Activity extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use HandlesAttachments;

    #[LiveProp]
    public Issue $issue;

    /** The comment being written; not `comment`, which canComment() would read. */
    #[LiveProp(writable: true)]
    public string $body = '';

    #[LiveProp(writable: true)]
    public bool $private = false;

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

    /** Why the last action did not go through, shown above the editor. */
    public ?string $error = null;

    /** The detail, read once per request; forgotten after an action changed the issue. */
    private ?IssueDetail $detail = null;

    public function __construct(
        private readonly WorkflowProvider $workflows,
        private readonly AccessControl $access,
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

        return $this->attachments->findUnattached($this->issue->getProject(), $this->viewer(), array_map(Uuid::fromString(...), $uuids));
    }

    public function getDetail(): IssueDetail
    {
        return $this->detail ??= $this->queries->ask(new ShowIssue($this->viewer(), (int) $this->issue->getProject()->getId(), $this->issue->getNumber()))
            ?? throw $this->createNotFoundException('The issue is no longer visible.');
    }

    /**
     * @return list<TransitionDefinition>
     */
    public function getTransitions(): array
    {
        $viewer = $this->viewer();
        $workflow = $this->workflows->definition($this->issue->getTracker()->getWorkflowId());

        return array_values(array_filter(
            $workflow->transitionsFrom((string) $this->issue->getPlace()),
            fn (TransitionDefinition $transition): bool => $this->access->canTransition($viewer, $transition, $this->issue->getProject()),
        ));
    }

    public function canComment(): bool
    {
        return $this->access->isGranted($this->viewer(), Permission::CommentCreate, $this->issue->getProject());
    }

    /**
     * The comments of the thread the viewer may edit or delete, with their
     * files: their own, or all of them with `comment.edit`.
     *
     * @return list<int> comment ids
     */
    public function getEditableComments(): array
    {
        $viewer = $this->viewer();
        $ids = [];
        foreach ($this->getDetail()->timeline as $entry) {
            $comment = $entry['comment'] ?? $entry['event']?->getComment();
            if (null !== $comment && $this->access->canEditComment($viewer, $comment)) {
                $ids[] = (int) $comment->getId();
            }
        }

        return $ids;
    }

    public function canMakePrivate(): bool
    {
        return $this->access->isGranted($this->viewer(), Permission::CommentSetPrivate, $this->issue->getProject());
    }

    #[LiveAction]
    public function postComment(CommandBus $commands): void
    {
        if ('' === trim($this->body)) {
            $this->error = $this->translator->trans('issue.comment.empty');

            return;
        }

        $this->run(
            fn () => $commands->dispatch(new AddComment((int) $this->viewer()->getId(), $this->issue->getKey(), $this->body, $this->private && $this->canMakePrivate(), $this->pending)),
            $this->translator->trans('issue.comment.added'),
        );
    }

    #[LiveAction]
    public function apply(CommandBus $commands, #[LiveArg] string $transition): void
    {
        $definition = null;
        foreach ($this->getTransitions() as $available) {
            if ($available->id === $transition) {
                $definition = $available;
            }
        }
        if (null === $definition) {
            return;
        }
        if ($definition->requiresComment && '' === trim($this->body)) {
            $this->error = $definition->commentPrompt ?? $this->translator->trans('issue.transition.comment_required');

            return;
        }

        $this->run(
            fn () => $commands->dispatch(new ApplyTransition((int) $this->viewer()->getId(), $this->issue->getKey(), $definition->id, '' === trim($this->body) ? null : $this->body, $this->private && $this->canMakePrivate(), $this->pending)),
            $this->translator->trans('issue.transition.applied', ['%key%' => $this->issue->getKey(), '%transition%' => $definition->label]),
        );
    }

    /**
     * Files chosen for the comment being written, or rewritten: sent now,
     * pending until the comment is posted or saved.
     */
    #[LiveAction]
    public function attach(Request $request, CommandBus $commands): void
    {
        [$sent, $this->error] = $this->sendFiles($request, $commands, $this->viewer(), $this->issue->getProject());
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

        try {
            $commands->dispatch(new EditComment((int) $this->viewer()->getId(), $this->editingCommentId, $this->commentBody, $this->editPending));
        } catch (IssueException|AccessDeniedException) {
            $this->error = $this->translator->trans('issue.edit.refused');

            return;
        }

        $this->detail = null;
        $this->editingCommentId = null;
        $this->editPending = [];
        $this->emit('issue:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('issue.comment.updated')]);
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

        try {
            $commands->dispatch(new DeleteComment((int) $this->viewer()->getId(), $this->deletingCommentId));
        } catch (IssueException|AccessDeniedException) {
            $this->error = $this->translator->trans('issue.edit.refused');

            return;
        }

        $this->detail = null;
        if ($this->editingCommentId === $this->deletingCommentId) {
            $this->cancelComment();
        }
        $this->deletingCommentId = null;
        $this->emit('issue:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('issue.comment.deleted')]);
    }

    /** A comment of the thread the viewer may edit, null otherwise. */
    private function editableComment(int $id): ?Comment
    {
        foreach ($this->getDetail()->timeline as $entry) {
            $comment = $entry['comment'] ?? $entry['event']?->getComment();
            if ($comment?->getId() === $id && $this->access->canEditComment($this->viewer(), $comment)) {
                return $comment;
            }
        }

        return null;
    }

    /** Removes a file of a posted comment. */
    #[LiveAction]
    public function removeAttachment(CommandBus $commands, #[LiveArg] string $uuid, #[LiveArg] string $name): void
    {
        $this->error = $this->removeFile($commands, $this->viewer(), $uuid);
        if (null === $this->error) {
            $this->detail = null;
            $this->emit('issue:changed');
            $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('attachment.removed', ['%name%' => $name])]);
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

    private function run(\Closure $dispatch, string $success): void
    {
        try {
            $dispatch();
        } catch (IssueException|AccessDeniedException) {
            $this->error = $this->translator->trans('issue.transition.refused');

            return;
        }

        $this->detail = null;
        $this->body = '';
        $this->private = false;
        $this->pending = [];
        $this->emit('issue:changed');
        // The editor sits outside the morph (data-live-ignore): it is told to empty itself.
        $this->dispatchBrowserEvent('editor:reset');
        $this->dispatchBrowserEvent('toast:show', ['message' => $success]);
    }

    private function viewer(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new \LogicException('The issue activity is only rendered for signed-in users.');
    }
}

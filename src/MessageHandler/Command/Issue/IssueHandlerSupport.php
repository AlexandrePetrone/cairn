<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Config\Definition\WorkflowDefinition;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\User;
use App\Repository\AttachmentRepository;
use App\Repository\CommentRepository;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Loading, visibility checks and attachment claiming shared by the issue
 * handlers.
 */
final class IssueHandlerSupport
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly IssueRepository $issues,
        private readonly AccessControl $access,
        private readonly AttachmentRepository $attachments,
        private readonly CommentRepository $comments,
    ) {
    }

    public function actor(int $actorId): User
    {
        return $this->users->find($actorId) ?? throw IssueException::notFound('User', $actorId);
    }

    /**
     * The issue, if the actor may see it; an invisible issue is reported as
     * not found so that private issues cannot be probed.
     */
    public function visibleIssue(User $actor, string $key): Issue
    {
        $issue = $this->issues->findOneByKey($key);
        if (null === $issue || !$this->access->canSee($actor, $issue)) {
            throw IssueException::notFound('Issue', $key);
        }

        return $issue;
    }

    /**
     * The values belong to the workflow and fill its required fields.
     *
     * @param array<string, mixed> $values
     */
    public function checkFields(WorkflowDefinition $workflow, array $values): void
    {
        foreach (array_keys($values) as $fieldId) {
            if (!isset($workflow->fields[$fieldId])) {
                throw IssueException::unknownField((string) $fieldId, $workflow->id);
            }
        }
        foreach ($workflow->fields as $usage) {
            $value = $values[$usage->field] ?? null;
            if ($usage->required && (null === $value || (\is_string($value) && '' === trim($value)))) {
                throw IssueException::missingField($usage->field);
            }
        }
    }

    /**
     * The comment, if the actor may edit it. A comment the actor may not
     * see is reported as not found, so that private comments cannot be
     * probed; one they see but may not edit is refused.
     */
    public function editableComment(User $actor, int $commentId): Comment
    {
        $comment = $this->comments->find($commentId);
        if (null === $comment || !$this->access->canSeeComment($actor, $comment)) {
            throw IssueException::notFound('Comment', $commentId);
        }
        $this->denyUnless($this->access->canEditComment($actor, $comment), 'edit a comment written by someone else');

        return $comment;
    }

    /**
     * Gives the actor's pending files to what they just wrote: the files
     * sent along (`uuids`) and those its text points to. A file sent by
     * someone else, or already claimed, is left alone.
     *
     * @param list<string> $uuids
     */
    public function claimAttachments(User $actor, Issue $issue, array $uuids, string $text, ?Comment $comment = null): void
    {
        $wanted = Attachment::referencedIn($text);
        foreach ($uuids as $uuid) {
            if (Uuid::isValid($uuid)) {
                $wanted[] = Uuid::fromString($uuid);
            }
        }

        foreach ($this->attachments->findUnattached($issue->getProject(), $actor, $wanted) as $attachment) {
            $attachment->attachTo($issue, $comment);
        }
    }

    public function denyUnless(bool $granted, string $what): void
    {
        if (!$granted) {
            throw new AccessDeniedException(\sprintf('Not allowed to %s.', $what));
        }
    }
}

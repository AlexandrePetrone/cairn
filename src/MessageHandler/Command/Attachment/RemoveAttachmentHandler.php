<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Attachment;

use App\Entity\JournalEntry;
use App\Event\AttachmentRemoved;
use App\Message\Command\Attachment\RemoveAttachment;
use App\MessageHandler\Command\Issue\IssueException;
use App\MessageHandler\Command\Issue\IssueHandlerSupport;
use App\Repository\AttachmentRepository;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * A file is removed by whoever may edit what it goes with, the issue or
 * the comment. A file the actor may not see, or still pending, is not
 * found. The file on disk goes with the row
 * (EventListener/AttachmentFileRemover).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RemoveAttachmentHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AttachmentRepository $attachments,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RemoveAttachment $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $attachment = $this->attachments->findOneByUuid(Uuid::fromString($command->uuid));
        $issue = $attachment?->getIssue();
        if (null === $attachment || null === $issue || !$this->access->canSeeAttachment($actor, $attachment)) {
            throw IssueException::notFound('Attachment', $command->uuid);
        }

        // A file follows what it goes with: the issue, or one of its comments.
        $comment = $attachment->getComment();
        $this->support->denyUnless(
            null === $comment ? $this->access->canEditIssue($actor, $issue) : $this->access->canEditComment($actor, $comment),
            'remove a file of a text written by someone else',
        );

        $entry = JournalEntry::attachmentRemoved($issue, $actor, $attachment);
        $this->entityManager->remove($attachment);
        $this->support->record(new AttachmentRemoved($issue, $actor, [$entry]));
    }
}

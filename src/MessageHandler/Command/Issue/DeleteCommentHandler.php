<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\JournalEntry;
use App\Message\Command\Issue\DeleteComment;
use App\Repository\AttachmentRepository;
use App\Repository\JournalEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The comment and its files go; the journal entry keeps the text, and the
 * entry of an action the comment came with stays, unlinked.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteCommentHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AttachmentRepository $attachments,
        private readonly JournalEntryRepository $journal,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteComment $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $comment = $this->support->editableComment($actor, $command->commentId);
        $issue = $comment->getIssue();

        $this->entityManager->persist(JournalEntry::commentDeleted($issue, $actor, $comment));
        foreach ($this->journal->findByComment($comment) as $entry) {
            $entry->detachComment();
        }
        foreach ($this->attachments->findBy(['comment' => $comment]) as $attachment) {
            $this->entityManager->remove($attachment);
        }
        $this->entityManager->remove($comment);
        $issue->touch();
    }
}

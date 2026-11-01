<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\JournalEntry;
use App\Message\Command\Issue\EditComment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class EditCommentHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(EditComment $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $comment = $this->support->editableComment($actor, $command->commentId);
        $issue = $comment->getIssue();

        $previous = $comment->getBody();
        if ($previous !== trim($command->body)) {
            $comment->edit($command->body);
            $this->entityManager->persist(JournalEntry::commentEdited($issue, $actor, $comment, $previous, $comment->getBody()));
        }
        $this->support->claimAttachments($actor, $issue, $command->attachments, $comment->getBody(), $comment);
        $issue->touch();
    }
}

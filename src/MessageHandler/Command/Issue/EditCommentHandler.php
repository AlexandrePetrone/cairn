<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\JournalEntry;
use App\Event\CommentEdited;
use App\Message\Command\Issue\EditComment;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class EditCommentHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
    ) {
    }

    public function __invoke(EditComment $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $comment = $this->support->editableComment($actor, $command->commentId);
        $issue = $comment->getIssue();

        $previous = $comment->getBody();
        $rewritten = $previous !== trim($command->body);
        if ($rewritten) {
            $comment->edit($command->body);
        }
        $this->support->claimAttachments($actor, $issue, $command->attachments, $comment->getBody(), $comment);
        if ($rewritten) {
            $this->support->record(new CommentEdited($issue, $actor, [JournalEntry::commentEdited($issue, $actor, $comment, $previous, $comment->getBody())], $comment));
        } else {
            $issue->touch();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\Comment;
use App\Event\CommentAdded;
use App\Message\Command\Issue\AddComment;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class AddCommentHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(AddComment $command): Comment
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $project = $issue->getProject();

        $this->support->denyUnless($this->access->isGranted($actor, Permission::CommentCreate, $project), 'comment');
        $this->support->denyUnless(!$command->private || $this->access->isGranted($actor, Permission::CommentSetPrivate, $project), 'make comments private');

        $comment = new Comment($issue, $actor, trim($command->body), $command->private);
        $this->entityManager->persist($comment);
        $this->support->claimAttachments($actor, $issue, $command->attachments, $comment->getBody(), $comment);
        $this->support->record(new CommentAdded($issue, $actor, [], $comment));

        return $comment;
    }
}

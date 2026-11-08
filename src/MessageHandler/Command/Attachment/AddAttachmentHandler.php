<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Attachment;

use App\Attachment\AttachmentStorage;
use App\Entity\Attachment;
use App\Entity\JournalEntry;
use App\Event\AttachmentAdded;
use App\Message\Command\Attachment\AddAttachment;
use App\MessageHandler\Command\Issue\IssueHandlerSupport;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A file added to an issue directly belongs with its text, so whoever may
 * edit the issue adds it. Journaled.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class AddAttachmentHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly AttachmentStorage $storage,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(AddAttachment $command): Attachment
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $this->support->denyUnless($this->access->canEditIssue($actor, $issue), 'add files to an issue written by someone else');

        $attachment = Attachment::fromUpload($command->file, $issue->getProject(), $issue, $actor);
        $this->storage->store($command->file, $attachment);
        $this->entityManager->persist($attachment);
        $this->support->record(new AttachmentAdded($issue, $actor, [JournalEntry::attachmentAdded($issue, $actor, $attachment)]));

        return $attachment;
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\JournalEntry;
use App\Message\Command\Issue\ChangeDescription;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ChangeDescriptionHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ChangeDescription $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);

        $this->support->denyUnless($this->access->canEditIssue($actor, $issue), 'rewrite the description of an issue written by someone else');

        if ($issue->getDescription() === $command->description) {
            return;
        }

        $previous = $issue->getDescription();
        $issue->describe($command->description);
        $this->entityManager->persist(JournalEntry::changed($issue, $actor, 'description', $previous, $issue->getDescription()));
        // Images pasted while rewriting it were uploaded pending.
        $this->support->claimAttachments($actor, $issue, [], $command->description);
    }
}

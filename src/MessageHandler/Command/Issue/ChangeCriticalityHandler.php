<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\JournalEntry;
use App\Message\Command\Issue\ChangeCriticality;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ChangeCriticalityHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ChangeCriticality $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);

        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueSetCriticality, $issue->getProject()), 'set the criticality');

        $previous = $issue->getCriticality();
        if ($previous === $command->criticality) {
            return;
        }

        $issue->setCriticality($command->criticality);
        $this->entityManager->persist(JournalEntry::changed($issue, $actor, 'criticality', $previous->value, $command->criticality->value));
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\JournalEntry;
use App\Message\Command\Issue\ChangeTitle;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ChangeTitleHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ChangeTitle $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);

        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueEdit, $issue->getProject()), 'edit issues');

        $previous = $issue->getTitle();
        if ($previous === trim($command->title)) {
            return;
        }

        $issue->retitle($command->title);
        $this->entityManager->persist(JournalEntry::changed($issue, $actor, 'title', $previous, $issue->getTitle()));
    }
}

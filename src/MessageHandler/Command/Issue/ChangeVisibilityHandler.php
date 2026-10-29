<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\JournalEntry;
use App\Message\Command\Issue\ChangeVisibility;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ChangeVisibilityHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ChangeVisibility $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);

        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueSetPrivate, $issue->getProject()), 'change the visibility');

        if (!$command->private && $issue->getTracker()->isPrivate()) {
            throw IssueException::privateTracker($issue->getTracker()->getName());
        }
        if ($issue->isPrivate() === $command->private) {
            return;
        }

        $command->private ? $issue->makePrivate() : $issue->makePublic();
        $this->entityManager->persist(JournalEntry::changed($issue, $actor, 'visibility', $command->private ? 'public' : 'private', $command->private ? 'private' : 'public'));
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\JournalEntry;
use App\Event\IssueUpdated;
use App\Message\Command\Issue\UntagIssue;
use App\Repository\IssueTagRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UntagIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly IssueTagRepository $issueTags,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(UntagIssue $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueSetTags, $issue->getProject()), 'tag issues');
        // Not carried, or a tag that is gone: nothing changed, nothing to tell.
        $carried = $this->issueTags->findOneBy(['issue' => $issue, 'tag' => $command->tagId]);
        if (null === $carried) {
            return;
        }

        $this->entityManager->remove($carried);
        $this->support->record(new IssueUpdated($issue, $actor, [JournalEntry::changed($issue, $actor, 'tag_removed', $carried->getTag()->getName(), null)]));
    }
}

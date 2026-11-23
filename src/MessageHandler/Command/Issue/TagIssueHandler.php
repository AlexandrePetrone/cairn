<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\IssueTag;
use App\Entity\JournalEntry;
use App\Event\IssueUpdated;
use App\Message\Command\Issue\TagIssue;
use App\MessageHandler\Command\Tag\TagException;
use App\Repository\IssueTagRepository;
use App\Repository\TagRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class TagIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly TagRepository $tags,
        private readonly IssueTagRepository $issueTags,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(TagIssue $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueSetTags, $issue->getProject()), 'tag issues');
        $tag = $this->tags->find($command->tagId) ?? throw TagException::notFound('Tag', $command->tagId);
        if ($tag->getProject() !== $issue->getProject()) {
            throw TagException::otherProject($tag->getName(), $issue->getKey());
        }
        // Already carried: nothing changed, nothing to tell.
        if (null !== $this->issueTags->findOneBy(['issue' => $issue, 'tag' => $tag])) {
            return;
        }

        $this->entityManager->persist(new IssueTag($issue, $tag));
        $this->support->record(new IssueUpdated($issue, $actor, [JournalEntry::changed($issue, $actor, 'tag_added', null, $tag->getName())]));
    }
}

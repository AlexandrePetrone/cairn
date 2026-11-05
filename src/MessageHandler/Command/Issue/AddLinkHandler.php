<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Config\ConfigRegistry;
use App\Entity\IssueLink;
use App\Entity\JournalEntry;
use App\Message\Command\Issue\AddLink;
use App\Repository\IssueLinkRepository;
use App\Repository\IssueRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Both issues get a journal entry, each with the label read from its side.
 * A symmetric link exists once whatever the direction; the same type in
 * both directions of an asymmetric one (A blocks B, B blocks A) is left to
 * the people who write it.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class AddLinkHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly IssueRepository $issues,
        private readonly IssueLinkRepository $links,
        private readonly AccessControl $access,
        private readonly ConfigRegistry $config,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(AddLink $command): IssueLink
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $this->support->denyUnless($this->access->isGranted($actor, Permission::IssueLink, $issue->getProject()), 'link issues');

        $definition = $this->config->definition();
        if (!$definition->hasLink($command->typeId)) {
            throw IssueLinkException::unknownType($command->typeId);
        }
        $type = $definition->link($command->typeId);

        $otherKey = mb_strtoupper(trim($command->otherKey));
        $other = $this->issues->findOneByKey($otherKey);
        if (null === $other || !$this->access->canSee($actor, $other)) {
            throw IssueLinkException::issueNotFound($otherKey);
        }
        if ($other === $issue) {
            throw IssueLinkException::itself($issue->getKey());
        }
        if ($other->getProject() !== $issue->getProject()) {
            if (!$actor->isInternal()) {
                throw IssueLinkException::otherProject($other->getKey());
            }
            if ($other->getProject()->isReadOnly()) {
                throw IssueLinkException::readOnlyProject($other->getKey());
            }
        }
        if (null !== $this->links->findBetween($issue, $type->id, $other, eitherWay: $type->isSymmetric())) {
            throw IssueLinkException::alreadyLinked($issue->getKey(), $other->getKey());
        }

        $link = new IssueLink($issue, $type->id, $other, $actor);
        $this->entityManager->persist($link);
        $this->entityManager->persist(JournalEntry::linked($issue, $actor, $type->label, $other));
        $this->entityManager->persist(JournalEntry::linked($other, $actor, $type->labelFromTarget(), $issue));
        $issue->touch();
        $other->touch();

        return $link;
    }
}

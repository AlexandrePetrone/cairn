<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Config\ConfigRegistry;
use App\Entity\JournalEntry;
use App\Event\IssuesUnlinked;
use App\Message\Command\Issue\RemoveLink;
use App\Repository\IssueLinkRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The row is deleted; both issues journal it, with the labels of the type
 * as the configuration names it now, or its id once it left it.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RemoveLinkHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly IssueLinkRepository $links,
        private readonly AccessControl $access,
        private readonly ConfigRegistry $config,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RemoveLink $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $link = $this->links->find($command->linkId) ?? throw IssueLinkException::linkNotFound($command->linkId);
        $source = $link->getSource();
        $target = $link->getTarget();
        if (!$this->access->canSee($actor, $source) || !$this->access->canSee($actor, $target)) {
            throw IssueLinkException::linkNotFound($command->linkId);
        }
        $this->support->denyUnless(
            $this->access->isGranted($actor, Permission::IssueLink, $source->getProject()) || $this->access->isGranted($actor, Permission::IssueLink, $target->getProject()),
            'remove links',
        );

        $definition = $this->config->definition();
        $type = $definition->hasLink($link->getTypeId()) ? $definition->link($link->getTypeId()) : null;
        $this->entityManager->remove($link);
        $this->support->record(new IssuesUnlinked($source, $actor, [JournalEntry::linked($source, $actor, $type->label ?? $link->getTypeId(), $target, removed: true)]));
        $this->support->record(new IssuesUnlinked($target, $actor, [JournalEntry::linked($target, $actor, $type?->labelFromTarget() ?? $link->getTypeId(), $source, removed: true)]));
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\LinkType;
use App\Message\Command\Config\DeleteLinkType;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\IssueLinkRepository;
use App\Repository\LinkTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeleteLinkTypeHandler
{
    public function __construct(
        private readonly LinkTypeRepository $types,
        private readonly IssueLinkRepository $links,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(DeleteLinkType $command): void
    {
        $type = $this->writing->get(LinkType::class, $command->id);

        // Links are never rewritten: a type still in use stays.
        $used = $this->links->countByType()[$type->getId()] ?? 0;
        if ($used > 0) {
            throw ConfigChangeException::linkTypeUsed($type->getLabel(), $used);
        }

        ListOrder::close($type, $this->types->findOrdered());
        $this->entityManager->remove($type);
        $this->writing->settle('link.deleted', ['%name%' => $type->getLabel()]);
    }
}

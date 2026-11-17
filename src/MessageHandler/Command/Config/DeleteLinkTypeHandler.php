<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\LinkType;
use App\Message\Command\Config\DeleteLinkType;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\LinkTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeleteLinkTypeHandler
{
    public function __construct(
        private readonly LinkTypeRepository $types,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigDeletions $deletions,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(DeleteLinkType $command): void
    {
        $type = $this->writing->get(LinkType::class, $command->id);

        if (null !== $refusal = $this->deletions->linkType($type)) {
            throw ConfigChangeException::because($refusal);
        }

        ListOrder::close($type, $this->types->findOrdered());
        $this->entityManager->remove($type);
        $this->writing->settle('link.deleted', ['%name%' => $type->getLabel()]);
    }
}

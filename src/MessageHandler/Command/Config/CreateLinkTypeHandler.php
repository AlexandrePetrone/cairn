<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\LinkType;
use App\Message\Command\Config\CreateLinkType;
use App\Repository\LinkTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateLinkTypeHandler
{
    public function __construct(
        private readonly LinkTypeRepository $types,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(CreateLinkType $command): LinkType
    {
        $id = $this->writing->key($command->label, fn (string $id): bool => null !== $this->types->find($id));
        $type = new LinkType($id, $command->label, $command->inverseLabel, \count($this->types->findOrdered()));
        $this->entityManager->persist($type);
        $this->writing->settle('link.created', ['%name%' => $type->getLabel()]);

        return $type;
    }
}

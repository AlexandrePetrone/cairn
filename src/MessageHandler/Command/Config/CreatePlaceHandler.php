<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Place;
use App\Message\Command\Config\CreatePlace;
use App\Repository\PlaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreatePlaceHandler
{
    public function __construct(
        private readonly PlaceRepository $places,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(CreatePlace $command): Place
    {
        $id = $this->writing->key($command->label, fn (string $id): bool => null !== $this->places->find($id));
        $place = new Place($id, $command->label, $command->status, \count($this->places->findOrdered()));
        $this->entityManager->persist($place);
        $this->writing->settle('place.created', ['%name%' => $place->getLabel()]);

        return $place;
    }
}

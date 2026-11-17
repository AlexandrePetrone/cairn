<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Place;
use App\Message\Command\Config\DeletePlace;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\PlaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeletePlaceHandler
{
    public function __construct(
        private readonly PlaceRepository $places,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigDeletions $deletions,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(DeletePlace $command): void
    {
        $place = $this->writing->get(Place::class, $command->id);

        if (null !== $refusal = $this->deletions->place($place)) {
            throw ConfigChangeException::because($refusal);
        }

        ListOrder::close($place, $this->places->findOrdered());
        $this->entityManager->remove($place);
        $this->writing->settle('place.deleted', ['%name%' => $place->getLabel()]);
    }
}

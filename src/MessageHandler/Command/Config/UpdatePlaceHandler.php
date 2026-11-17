<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Place;
use App\Message\Command\Config\UpdatePlace;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\PlaceRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdatePlaceHandler
{
    public function __construct(
        private readonly PlaceRepository $places,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(UpdatePlace $command): void
    {
        $place = $this->writing->get(Place::class, $command->id);

        if (null !== $command->label) {
            $place->rename($command->label);
        }
        if (null !== $command->status) {
            // The issues standing there are open or closed by this status, read where it is: none is touched.
            $place->changeStatus($command->status);
        }
        if (null !== $command->position) {
            ListOrder::move($place, $this->places->findOrdered(), $command->position);
        }
        $this->writing->settle('place.updated', ['%name%' => $place->getLabel()]);
    }
}

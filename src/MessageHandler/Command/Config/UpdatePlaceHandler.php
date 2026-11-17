<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Place;
use App\Message\Command\Config\UpdatePlace;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\IssueRepository;
use App\Repository\PlaceRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdatePlaceHandler
{
    public function __construct(
        private readonly PlaceRepository $places,
        private readonly IssueRepository $issues,
        private readonly ClockInterface $clock,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(UpdatePlace $command): void
    {
        $place = $this->writing->get(Place::class, $command->id);

        if (null !== $command->label) {
            $place->rename($command->label);
        }
        $aligned = null;
        if (null !== $command->status && $command->status !== $place->getStatus()) {
            $place->changeStatus($command->status);
            // An issue copies the status of its place: those standing there follow it, closed or reopened.
            $aligned = $this->issues->alignStatus($place->getId(), $command->status, $this->clock->now());
        }
        if (null !== $command->position) {
            ListOrder::move($place, $this->places->findOrdered(), $command->position);
        }
        null === $aligned
            ? $this->writing->settle('place.updated', ['%name%' => $place->getLabel()])
            : $this->writing->settle('place.status', ['%name%' => $place->getLabel(), '%count%' => $aligned]);
    }
}

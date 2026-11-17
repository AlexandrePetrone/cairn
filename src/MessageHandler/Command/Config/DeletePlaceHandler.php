<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Place;
use App\Message\Command\Config\DeletePlace;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\IssueRepository;
use App\Repository\PlaceRepository;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeletePlaceHandler
{
    public function __construct(
        private readonly PlaceRepository $places,
        private readonly WorkflowRepository $workflows,
        private readonly IssueRepository $issues,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(DeletePlace $command): void
    {
        $place = $this->writing->get(Place::class, $command->id);

        $workflows = $this->workflows->labelsUsingPlace($place);
        if ([] !== $workflows) {
            throw ConfigChangeException::placeUsed($place->getLabel(), $workflows);
        }
        // No workflow uses it, so no issue should stand there: checked all the same, an issue names its place by its key alone.
        $standing = $this->issues->countOnPlace($place->getId());
        if ($standing > 0) {
            throw ConfigChangeException::placeHoldsIssues($place->getLabel(), $standing);
        }

        ListOrder::close($place, $this->places->findOrdered());
        $this->entityManager->remove($place);
        $this->writing->settle('place.deleted', ['%name%' => $place->getLabel()]);
    }
}

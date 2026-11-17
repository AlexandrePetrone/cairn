<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Place;
use App\Entity\Workflow;
use App\Entity\WorkflowPlace;
use App\Message\Command\Config\AddWorkflowPlace;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class AddWorkflowPlaceHandler
{
    public function __construct(
        private readonly WorkflowRepository $workflows,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(AddWorkflowPlace $command): void
    {
        $workflow = $this->writing->get(Workflow::class, $command->workflowId);
        $place = $this->writing->get(Place::class, $command->placeId);
        if (null !== $this->workflows->findPlace($workflow, $place)) {
            throw ConfigChangeException::alreadyAPlaceOf($place->getLabel(), $workflow->getLabel());
        }

        $used = $this->workflows->placesOf($workflow);
        $added = new WorkflowPlace($workflow, $place, \count($used));
        $this->entityManager->persist($added);
        if (null !== $command->position) {
            ListOrder::move($added, $used, $command->position);
        }
        $this->writing->settle('workflow.place_added', ['%place%' => $place->getLabel(), '%workflow%' => $workflow->getLabel()]);
    }
}

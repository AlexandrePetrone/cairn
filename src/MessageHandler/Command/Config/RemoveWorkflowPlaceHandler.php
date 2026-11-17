<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Place;
use App\Entity\Workflow;
use App\Message\Command\Config\RemoveWorkflowPlace;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\IssueRepository;
use App\Repository\TransitionRepository;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RemoveWorkflowPlaceHandler
{
    public function __construct(
        private readonly WorkflowRepository $workflows,
        private readonly TransitionRepository $transitions,
        private readonly IssueRepository $issues,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(RemoveWorkflowPlace $command): void
    {
        $workflow = $this->writing->get(Workflow::class, $command->workflowId);
        $place = $this->writing->get(Place::class, $command->placeId);
        $used = $this->workflows->findPlace($workflow, $place) ?? throw ConfigChangeException::notAPlaceOf($place->getLabel(), $workflow->getLabel());
        if ($workflow->getInitial() === $place) {
            throw ConfigChangeException::initialPlace($place->getLabel(), $workflow->getLabel());
        }

        $standing = $this->issues->countOnPlace($place->getId(), $workflow->getId());
        $target = null;
        if (null !== $command->moveIssuesTo && $command->moveIssuesTo !== $place->getId()) {
            $target = $this->writing->placeOf($workflow, $command->moveIssuesTo);
        }
        if ($standing > 0 && null === $target) {
            throw ConfigChangeException::issuesToMove($place->getLabel(), $standing);
        }

        if (null !== $target && $standing > 0) {
            // In bulk and without a word in their history: entering a closed place closes them, leaving one reopens them.
            $this->issues->moveFromPlace($workflow->getId(), $place->getId(), $place->getStatus(), $target->getId(), $target->getStatus(), $this->clock->now());
        }

        foreach ($this->transitions->findByWorkflow($workflow) as $transition) {
            if ($transition->getTarget() === $place) {
                $this->writing->removeTransition($transition);
                continue;
            }
            $sources = $this->transitions->sourcesOf($transition);
            foreach ($sources as $source) {
                if ($source->getPlace() !== $place) {
                    continue;
                }
                // An action that started from there only has nowhere to start from: it goes too.
                1 === \count($sources) ? $this->writing->removeTransition($transition) : $this->entityManager->remove($source);
            }
        }

        ListOrder::close($used, $this->workflows->placesOf($workflow));
        $this->entityManager->remove($used);
        $this->writing->settle('workflow.place_removed', ['%place%' => $place->getLabel(), '%workflow%' => $workflow->getLabel(), '%count%' => null === $target ? 0 : $standing]);
    }
}

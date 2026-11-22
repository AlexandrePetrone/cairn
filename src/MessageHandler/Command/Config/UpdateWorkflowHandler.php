<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Field;
use App\Entity\Workflow;
use App\Entity\WorkflowField;
use App\Entity\WorkflowPlace;
use App\Message\Command\Config\UpdateWorkflow;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateWorkflowHandler
{
    public function __construct(
        private readonly WorkflowRepository $workflows,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(UpdateWorkflow $command): void
    {
        $workflow = $this->writing->get(Workflow::class, $command->id);
        $used = $this->workflows->placesOf($workflow);

        $initial = null === $command->initial ? null : $this->writing->placeOf($workflow, $command->initial);
        if (null !== $command->places) {
            // An order, nothing else: a place enters or leaves through its own commands, which say what becomes of the issues.
            $current = array_map(static fn (WorkflowPlace $place): string => $place->getPlace()->getId(), $used);
            if (\count($command->places) !== \count($current) || [] !== array_diff($current, $command->places)) {
                throw ConfigChangeException::otherPlaces($workflow->getLabel());
            }
        }
        foreach ($command->aside ?? [] as $placeId => $besideId) {
            $place = $this->writing->placeOf($workflow, $placeId);
            // A place stands beside the path, not beside another that left it.
            if (isset($command->aside[$besideId]) || $placeId === $besideId) {
                throw ConfigChangeException::besideThePath($place->getLabel(), $this->writing->placeOf($workflow, $besideId)->getLabel());
            }
            $this->writing->placeOf($workflow, $besideId);
        }
        // The path starts where the issues do: the initial place is on it, whichever of the two changes.
        $aside = $command->aside ?? [];
        if (null === $command->aside) {
            foreach ($used as $place) {
                if (null !== $place->getBeside()) {
                    $aside[$place->getPlace()->getId()] = $place->getBeside()->getPlace()->getId();
                }
            }
        }
        $start = $initial ?? $workflow->getInitial();
        if (isset($aside[$start->getId()])) {
            throw ConfigChangeException::initialAside($start->getLabel(), $workflow->getLabel());
        }
        $fields = [];
        foreach ($command->fields ?? [] as $fieldId => $required) {
            $fields[$fieldId] = $this->writing->get(Field::class, $fieldId);
        }

        if (null !== $command->label) {
            $workflow->rename($command->label);
        }
        if (null !== $initial) {
            $workflow->startOn($initial);
        }
        if (null !== $command->places) {
            $positions = array_flip($command->places);
            foreach ($used as $place) {
                $place->moveTo($positions[$place->getPlace()->getId()]);
            }
        }
        if (null !== $command->aside) {
            $rows = [];
            foreach ($used as $place) {
                $rows[$place->getPlace()->getId()] = $place;
            }
            foreach ($rows as $placeId => $place) {
                isset($command->aside[$placeId]) ? $place->standBeside($rows[$command->aside[$placeId]]) : $place->putOnThePath();
            }
        }
        if (null !== $command->fields) {
            $asked = [];
            foreach ($this->workflows->fieldsOf($workflow) as $usage) {
                if (isset($fields[$usage->getField()->getId()])) {
                    $asked[$usage->getField()->getId()] = $usage;
                } else {
                    $this->entityManager->remove($usage);
                }
            }
            foreach (array_keys($command->fields) as $position => $fieldId) {
                $usage = $asked[$fieldId] ?? new WorkflowField($workflow, $fields[$fieldId]);
                $usage->setRequired($command->fields[$fieldId]);
                $usage->moveTo($position);
                $this->entityManager->persist($usage);
            }
        }
        if (null !== $command->position) {
            ListOrder::move($workflow, $this->workflows->findOrdered(), $command->position);
        }
        $this->writing->settle('workflow.updated', ['%name%' => $workflow->getLabel()]);
    }
}

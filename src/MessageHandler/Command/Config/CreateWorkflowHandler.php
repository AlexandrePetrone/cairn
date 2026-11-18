<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Place;
use App\Entity\Workflow;
use App\Entity\WorkflowPlace;
use App\Message\Command\Config\CreateWorkflow;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateWorkflowHandler
{
    public function __construct(
        private readonly WorkflowRepository $workflows,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(CreateWorkflow $command): Workflow
    {
        $id = $this->writing->key($command->label, fn (string $id): bool => null !== $this->workflows->find($id));
        $initial = $this->writing->get(Place::class, $command->initial);

        $workflow = new Workflow($id, $command->label, $initial, \count($this->workflows->findOrdered()));
        $this->entityManager->persist($workflow);
        $this->entityManager->persist(new WorkflowPlace($workflow, $initial));
        $this->writing->settle('workflow.created', ['%name%' => $workflow->getLabel()]);

        return $workflow;
    }
}

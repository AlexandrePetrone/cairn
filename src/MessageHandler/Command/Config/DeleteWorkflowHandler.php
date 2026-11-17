<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Workflow;
use App\Message\Command\Config\DeleteWorkflow;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\TransitionRepository;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeleteWorkflowHandler
{
    public function __construct(
        private readonly WorkflowRepository $workflows,
        private readonly TransitionRepository $transitions,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigDeletions $deletions,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(DeleteWorkflow $command): void
    {
        $workflow = $this->writing->get(Workflow::class, $command->id);

        if (null !== $refusal = $this->deletions->workflow($workflow)) {
            throw ConfigChangeException::because($refusal);
        }

        ListOrder::close($workflow, $this->workflows->findOrdered());
        // Its actions, places and fields go with it.
        array_map($this->writing->removeTransition(...), $this->transitions->findByWorkflow($workflow));
        array_map($this->entityManager->remove(...), $this->workflows->placesOf($workflow));
        array_map($this->entityManager->remove(...), $this->workflows->fieldsOf($workflow));
        $this->entityManager->remove($workflow);
        $this->writing->settle('workflow.deleted', ['%name%' => $workflow->getLabel()]);
    }
}

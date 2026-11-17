<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Workflow;
use App\Message\Command\Config\DeleteTransition;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\TransitionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeleteTransitionHandler
{
    public function __construct(
        private readonly TransitionRepository $transitions,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(DeleteTransition $command): void
    {
        $workflow = $this->writing->get(Workflow::class, $command->workflowId);
        $transition = $this->writing->transition($workflow, $command->id);

        ListOrder::close($transition, $this->transitions->findByWorkflow($workflow));
        $this->writing->removeTransition($transition);
        $this->writing->settle('action.deleted', ['%name%' => $transition->getLabel(), '%workflow%' => $workflow->getLabel()]);
    }
}

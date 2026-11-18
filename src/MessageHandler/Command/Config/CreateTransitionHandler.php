<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Transition;
use App\Entity\Workflow;
use App\Message\Command\Config\CreateTransition;
use App\Repository\TransitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateTransitionHandler
{
    public function __construct(
        private readonly TransitionRepository $transitions,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(CreateTransition $command): Transition
    {
        $workflow = $this->writing->get(Workflow::class, $command->workflowId);
        $key = $this->writing->key($command->label, fn (string $key): bool => null !== $this->transitions->findOne($workflow, $key));
        $target = $this->writing->placeOf($workflow, $command->to);
        $this->writing->assertPairsFree($workflow, null, $command->from, $command->to);
        // Everything is checked before the action is written: a refusal leaves nothing behind.
        $from = $this->writing->places($workflow, $command->from);
        $roles = $this->writing->roles($command->roles);

        $transition = new Transition($workflow, $key, $command->label, $target, $command->style, $command->requiresComment, $command->commentPrompt, $command->description, \count($this->transitions->findByWorkflow($workflow)));
        $this->entityManager->persist($transition);
        $this->writing->triggerFrom($transition, $from);
        $this->writing->allow($transition, $roles);
        $this->writing->settle('action.created', ['%name%' => $transition->getLabel(), '%workflow%' => $workflow->getLabel()]);

        return $transition;
    }
}

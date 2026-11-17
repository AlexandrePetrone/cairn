<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Workflow;
use App\Message\Command\Config\UpdateTransition;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\TransitionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateTransitionHandler
{
    public function __construct(
        private readonly TransitionRepository $transitions,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(UpdateTransition $command): void
    {
        $workflow = $this->writing->get(Workflow::class, $command->workflowId);
        $transition = $this->writing->transition($workflow, $command->id);

        $target = null === $command->to ? null : $this->writing->placeOf($workflow, $command->to);
        if (null !== $command->from || null !== $command->to) {
            $from = $command->from ?? array_map(static fn ($source): string => $source->getPlace()->getId(), $this->transitions->sourcesOf($transition));
            $this->writing->assertPairsFree($workflow, $transition->getKey(), $from, $command->to ?? $transition->getTarget()->getId());
        }

        if (null !== $command->label) {
            $transition->rename($command->label);
        }
        if (null !== $target) {
            $transition->leadTo($target);
        }
        if (null !== $command->from) {
            $this->writing->triggerFrom($transition, $command->from);
        }
        if (null !== $command->roles) {
            $this->writing->allow($transition, $command->roles);
        }
        if (null !== $command->style) {
            $transition->restyle($command->style);
        }
        if (null !== $command->requiresComment || null !== $command->commentPrompt) {
            $transition->requireComment($command->requiresComment ?? $transition->requiresComment(), $command->commentPrompt ?? $transition->getCommentPrompt());
        }
        if (null !== $command->description) {
            $transition->setDescription($command->description);
        }
        if (null !== $command->position) {
            ListOrder::move($transition, $this->transitions->findByWorkflow($workflow), $command->position);
        }
        $this->writing->settle('action.updated', ['%name%' => $transition->getLabel(), '%workflow%' => $workflow->getLabel()]);
    }
}

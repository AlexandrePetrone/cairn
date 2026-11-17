<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Budget;

use App\Message\Command\Budget\UpdateBudgetLine;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\BudgetLineRepository;
use App\Security\Permission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateBudgetLineHandler
{
    public function __construct(
        private readonly BudgetLineRepository $lines,
        private readonly ProjectActor $actor,
    ) {
    }

    public function __invoke(UpdateBudgetLine $command): void
    {
        $line = $this->lines->find($command->lineId) ?? throw BudgetException::notFound('Budget line', $command->lineId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::BudgetManage, $line->getBudget()->getProject());
        BudgetException::unlessBudgeted($line->getBudget()->getProject());

        $status = $command->status ?? $line->getStatus();
        $quantity = $command->withoutQuantity ? null : ($command->quantity ?? $line->getQuantity());
        $label = $command->label ?? $line->getLabel();
        BudgetException::unlessComplete(null !== $line->getIssue(), $label, $status, $quantity);

        $line->describe($label);
        $line->mark($status, $quantity);
    }
}

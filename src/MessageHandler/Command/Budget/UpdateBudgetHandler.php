<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Budget;

use App\Message\Command\Budget\UpdateBudget;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\BudgetRepository;
use App\Security\Permission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateBudgetHandler
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly ProjectActor $actor,
    ) {
    }

    public function __invoke(UpdateBudget $command): void
    {
        $budget = $this->budgets->find($command->budgetId) ?? throw BudgetException::notFound('Budget', $command->budgetId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::BudgetManage, $budget->getProject());
        BudgetException::unlessBudgeted($budget->getProject());
        if (null !== $command->name) {
            $other = $this->budgets->findOneByName($budget->getProject(), $command->name);
            if (null !== $other && $other !== $budget) {
                throw BudgetException::nameTaken(trim($command->name));
            }
            $budget->rename($command->name);
        }

        if (null !== $command->volume) {
            $budget->resize($command->volume);
        }
        if (true === $command->closed) {
            $budget->close();
        } elseif (false === $command->closed) {
            $budget->reopen();
        }
    }
}

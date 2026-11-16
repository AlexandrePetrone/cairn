<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Budget;

use App\Message\Command\Budget\DeleteBudget;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\BudgetLineRepository;
use App\Repository\BudgetRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes a closed budget with its lines. An open budget is refused, so
 * that none goes by a slip: whoever manages the budgets closes it first.
 * The issues its lines named are left alone, as they always are.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteBudgetHandler
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly BudgetLineRepository $lines,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteBudget $command): void
    {
        $budget = $this->budgets->find($command->budgetId) ?? throw BudgetException::notFound('Budget', $command->budgetId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::BudgetManage, $budget->getProject());
        BudgetException::unlessBudgeted($budget->getProject());
        if ($budget->isOpen()) {
            throw BudgetException::stillOpen($budget->getName());
        }

        foreach ($this->lines->findBy(['budget' => $budget]) as $line) {
            $this->entityManager->remove($line);
        }
        $this->entityManager->remove($budget);
    }
}

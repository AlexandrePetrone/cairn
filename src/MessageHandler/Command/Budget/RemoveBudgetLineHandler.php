<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Budget;

use App\Message\Command\Budget\RemoveBudgetLine;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\BudgetLineRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RemoveBudgetLineHandler
{
    public function __construct(
        private readonly BudgetLineRepository $lines,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RemoveBudgetLine $command): void
    {
        $line = $this->lines->find($command->lineId) ?? throw BudgetException::notFound('Budget line', $command->lineId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::BudgetManage, $line->getBudget()->getProject());
        BudgetException::unlessBudgeted($line->getBudget()->getProject());

        $this->entityManager->remove($line);
    }
}

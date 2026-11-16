<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Budget;

use App\Entity\Budget;
use App\Message\Command\Budget\CreateBudget;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\BudgetRepository;
use App\Repository\ProjectRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateBudgetHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly BudgetRepository $budgets,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateBudget $command): Budget
    {
        $project = $this->projects->find($command->projectId) ?? throw BudgetException::notFound('Project', $command->projectId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::BudgetManage, $project);
        BudgetException::unlessBudgeted($project);
        if (null !== $this->budgets->findOneByName($project, $command->name)) {
            throw BudgetException::nameTaken(trim($command->name));
        }

        $budget = new Budget($project, $command->name, $command->volume);
        $this->entityManager->persist($budget);

        return $budget;
    }
}

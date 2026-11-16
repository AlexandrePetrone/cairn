<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Budget;

use App\Entity\BudgetLine;
use App\Message\Command\Budget\AddBudgetLine;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\BudgetRepository;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The line is only written on the budget: the issue it names is left
 * alone, so nothing is journaled, no event is recorded and nobody is
 * notified.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class AddBudgetLineHandler
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly IssueRepository $issues,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(AddBudgetLine $command): BudgetLine
    {
        $budget = $this->budgets->find($command->budgetId) ?? throw BudgetException::notFound('Budget', $command->budgetId);
        $project = $budget->getProject();
        $this->actor->denyUnlessGranted($command->actorId, Permission::BudgetManage, $project);
        BudgetException::unlessBudgeted($project);
        if (!$budget->isOpen()) {
            throw BudgetException::closed($budget->getName());
        }

        $issue = null;
        if (null !== $command->issueId) {
            $issue = $this->issues->find($command->issueId);
            $actor = $this->users->find($command->actorId);
            // an issue the actor does not see does not exist for them
            if (null === $issue || null === $actor || !$this->access->canSee($actor, $issue)) {
                throw BudgetException::notFound('Issue', $command->issueId);
            }
            if ($issue->getProject()->getId() !== $project->getId()) {
                throw BudgetException::issueOutsideProject($issue->getKey());
            }
        }
        BudgetException::unlessComplete(null !== $issue, $command->label, $command->status, $command->quantity);

        $line = new BudgetLine($budget, $issue, $command->label);
        $line->mark($command->status, $command->quantity);
        $this->entityManager->persist($line);

        return $line;
    }
}

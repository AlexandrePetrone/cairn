<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Automation\RuleReferences;
use App\Entity\Role;
use App\Entity\Transition;
use App\Message\Command\Config\DeleteRole;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\MembershipRepository;
use App\Repository\RoleRepository;
use App\Repository\TransitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeleteRoleHandler
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly MembershipRepository $memberships,
        private readonly TransitionRepository $transitions,
        private readonly RuleReferences $rules,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(DeleteRole $command): void
    {
        $role = $this->writing->get(Role::class, $command->id);

        $held = $this->memberships->countHolding($role->getId())['all'];
        if ($held > 0) {
            throw ConfigChangeException::roleHeld($role->getLabel(), $held);
        }
        // An action must keep a role that triggers it: the role leaves the actions first.
        $actions = array_map(static fn (Transition $transition): string => \sprintf('%s (%s)', $transition->getLabel(), $transition->getWorkflow()->getLabel()), $this->transitions->findNamingRole($role));
        if ([] !== $actions) {
            throw ConfigChangeException::roleTriggersActions($role->getLabel(), $actions);
        }

        // Nor is it left behind in the rules that hand issues to its referent.
        $rules = $this->rules->toRole($role->getId());
        if ([] !== $rules) {
            throw ConfigChangeException::roleAimedAtByRules($role->getLabel(), $rules);
        }

        ListOrder::close($role, $this->roles->findOrdered());
        // Its permissions go with it.
        array_map($this->entityManager->remove(...), $this->roles->grantsOf($role));
        $this->entityManager->remove($role);
        $this->writing->settle('role.deleted', ['%name%' => $role->getLabel()]);
    }
}

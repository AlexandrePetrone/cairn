<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Role;
use App\Message\Command\Config\UpdateRole;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\MembershipRepository;
use App\Repository\RoleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateRoleHandler
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly MembershipRepository $memberships,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(UpdateRole $command): void
    {
        $role = $this->writing->get(Role::class, $command->id);

        if (true === $command->internalOnly && !$role->isInternalOnly()) {
            // An internal-only role never goes to an external user: those who hold it come first.
            $external = $this->memberships->countHolding($role->getId())['external'];
            if ($external > 0) {
                throw ConfigChangeException::roleHeldByExternalUsers($role->getLabel(), $external);
            }
        }
        if (false === $command->internalOnly && $role->isInternalOnly()) {
            foreach ($this->roles->grantsOf($role) as $grant) {
                if ($grant->getPermission()->internalOnly()) {
                    throw ConfigChangeException::roleHoldsInternalPermissions($role->getLabel());
                }
            }
        }

        if (null !== $command->label) {
            $role->rename($command->label);
        }
        if (null !== $command->internalOnly) {
            $command->internalOnly ? $role->makeInternalOnly() : $role->openToExternalUsers();
        }
        if (null !== $command->followsNewIssues) {
            $command->followsNewIssues ? $role->followNewIssues() : $role->stopFollowingNewIssues();
        }
        if (null !== $command->position) {
            ListOrder::move($role, $this->roles->findOrdered(), $command->position);
        }
        $this->writing->settle('role.updated', ['%name%' => $role->getLabel()]);
    }
}

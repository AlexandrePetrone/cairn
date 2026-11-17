<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Role;
use App\Entity\RolePermission;
use App\Message\Command\Config\GrantPermission;
use App\Repository\RoleRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class GrantPermissionHandler
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(GrantPermission $command): void
    {
        $role = $this->writing->get(Role::class, $command->roleId);
        $permission = Permission::tryFrom($command->permission) ?? throw ConfigChangeException::unknownPermission($command->permission);
        // What opens what external users never see goes to internal-only roles.
        if ($permission->internalOnly() && !$role->isInternalOnly()) {
            throw ConfigChangeException::permissionForInternalRoles($role->getLabel());
        }

        if (null === $this->roles->findGrant($role, $permission)) {
            $this->entityManager->persist(new RolePermission($role, $permission));
            $this->writing->settle('permission.granted', ['%permission%' => $this->writing->permissionLabel($permission), '%role%' => $role->getLabel()]);
        }
    }
}

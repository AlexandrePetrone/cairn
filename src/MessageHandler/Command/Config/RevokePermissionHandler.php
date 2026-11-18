<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Role;
use App\Message\Command\Config\RevokePermission;
use App\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RevokePermissionHandler
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(RevokePermission $command): void
    {
        $role = $this->writing->get(Role::class, $command->roleId);
        $permission = $command->permission;

        $grant = $this->roles->findGrant($role, $permission);
        if (null !== $grant) {
            $this->entityManager->remove($grant);
            $this->writing->settle('permission.revoked', ['%permission%' => $this->writing->permissionLabel($permission), '%role%' => $role->getLabel()]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Role;
use App\Message\Command\Config\DeleteRole;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeleteRoleHandler
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigDeletions $deletions,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(DeleteRole $command): void
    {
        $role = $this->writing->get(Role::class, $command->id);

        if (null !== $refusal = $this->deletions->role($role)) {
            throw ConfigChangeException::because($refusal);
        }

        ListOrder::close($role, $this->roles->findOrdered());
        // Its permissions go with it.
        array_map($this->entityManager->remove(...), $this->roles->grantsOf($role));
        $this->entityManager->remove($role);
        $this->writing->settle('role.deleted', ['%name%' => $role->getLabel()]);
    }
}

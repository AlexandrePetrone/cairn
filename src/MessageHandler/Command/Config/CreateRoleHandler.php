<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Role;
use App\Message\Command\Config\CreateRole;
use App\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateRoleHandler
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(CreateRole $command): Role
    {
        $id = $this->writing->key($command->label, fn (string $id): bool => null !== $this->roles->find($id));
        $role = new Role($id, $command->label, $command->internalOnly, $command->followsNewIssues, $command->freeWorkflow, \count($this->roles->findOrdered()));
        $this->entityManager->persist($role);
        $this->writing->settle('role.created', ['%name%' => $role->getLabel()]);

        return $role;
    }
}

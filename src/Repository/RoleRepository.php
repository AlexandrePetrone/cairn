<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Role;
use App\Entity\RolePermission;
use App\Security\Permission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Role>
 */
class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    /**
     * @return list<Role> in the order people gave them
     */
    public function findOrdered(): array
    {
        return $this->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<RolePermission>
     */
    public function grantsOf(Role $role): array
    {
        return $this->getEntityManager()->getRepository(RolePermission::class)->findBy(['role' => $role]);
    }

    public function findGrant(Role $role, Permission $permission): ?RolePermission
    {
        return $this->getEntityManager()->getRepository(RolePermission::class)->findOneBy(['role' => $role, 'permission' => $permission]);
    }
}

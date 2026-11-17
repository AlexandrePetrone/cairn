<?php

declare(strict_types=1);

namespace App\Entity;

use App\Security\Permission;
use Doctrine\ORM\Mapping as ORM;

/**
 * A permission a role holds, outside the workflows: one row per pair.
 */
#[ORM\Entity]
#[ORM\Table(name: 'config_role_permission')]
#[ORM\UniqueConstraint(name: 'config_role_permission_unique', columns: ['role_id', 'permission'])]
class RolePermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Role $role;

    #[ORM\Column(length: 60, enumType: Permission::class)]
    private Permission $permission;

    public function __construct(Role $role, Permission $permission)
    {
        $this->role = $role;
        $this->permission = $permission;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getPermission(): Permission
    {
        return $this->permission;
    }
}

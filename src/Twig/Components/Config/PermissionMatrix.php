<?php

declare(strict_types=1);

namespace App\Twig\Components\Config;

use App\Config\ConfigRegistry;
use App\Config\Definition\RoleDefinition;
use App\Message\Command\Config\GrantPermission;
use App\Message\Command\Config\RevokePermission;
use App\Message\CommandBus;
use App\Security\Permission;
use App\Twig\Components\DispatchesCommands;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The rights outside the workflows, one box per right and role: ticking
 * one grants it, at once. A right that opens what external users never see
 * is offered to the roles reserved to internal users only.
 */
#[AsLiveComponent('Config:PermissionMatrix')]
final class PermissionMatrix extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    public function __construct(
        private readonly ConfigRegistry $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The live endpoint is not under `/admin`, and the commands carry no actor.
     */
    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }

    /**
     * @return array<string, RoleDefinition>
     */
    public function getRoles(): array
    {
        return $this->config->definition()->roles;
    }

    /**
     * @return list<Permission>
     */
    public function getPermissions(): array
    {
        return Permission::cases();
    }

    public function holds(RoleDefinition $role, Permission $permission): bool
    {
        return \in_array($role->id, $this->config->definition()->rolesGranted($permission), true);
    }

    public function offered(RoleDefinition $role, Permission $permission): bool
    {
        return $role->internalOnly || !$permission->internalOnly();
    }

    #[LiveAction]
    public function toggle(CommandBus $commands, #[LiveArg] string $role, #[LiveArg] string $permission): void
    {
        $right = Permission::tryFrom($permission) ?? throw $this->createNotFoundException();
        $granted = \in_array($role, $this->config->definition()->rolesGranted($right), true);

        $this->tryCommand($commands, $granted ? new RevokePermission($role, $right) : new GrantPermission($role, $right));
    }

    /**
     * The roles changed beside it: their order, their names.
     */
    #[LiveListener('config:changed')]
    public function refresh(): void
    {
    }
}

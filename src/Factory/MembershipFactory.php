<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Membership;
use App\Entity\Role;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * Give `workspace` or `project`, exactly one of the two.
 *
 * @extends PersistentObjectFactory<Membership>
 */
final class MembershipFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Membership::class;
    }

    protected function defaults(): array
    {
        return [
            'user' => UserFactory::new(),
            'role' => 'client',
        ];
    }

    protected function initialize(): static
    {
        // `role` is given by its key, as everywhere: the row it names must exist.
        return $this->instantiateWith(static function (array $attributes): Membership {
            $role = repository(Role::class)->find($attributes['role']) ?? throw new \LogicException(\sprintf('No role "%s" in the configuration.', $attributes['role']));

            return isset($attributes['project'])
                ? Membership::onProject($attributes['user'], $attributes['project'], $role)
                : Membership::onWorkspace($attributes['user'], $attributes['workspace'], $role);
        });
    }
}

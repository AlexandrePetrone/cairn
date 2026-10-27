<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Membership;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

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
        return $this->instantiateWith(static fn (array $attributes): Membership => isset($attributes['project'])
            ? Membership::onProject($attributes['user'], $attributes['project'], $attributes['role'])
            : Membership::onWorkspace($attributes['user'], $attributes['workspace'], $attributes['role']));
    }
}

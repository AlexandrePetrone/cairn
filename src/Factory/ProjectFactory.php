<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Project;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Project>
 */
final class ProjectFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Project::class;
    }

    protected function defaults(): array
    {
        return [
            'workspace' => WorkspaceFactory::new(),
            'name' => ucfirst(self::faker()->word()).' '.self::faker()->word(),
            'key' => strtoupper(self::faker()->unique()->lexify('???')),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::withConstructor()->alwaysForce());
    }
}

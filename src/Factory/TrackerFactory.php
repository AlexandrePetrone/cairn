<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Tracker;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Tracker>
 */
final class TrackerFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Tracker::class;
    }

    protected function defaults(): array
    {
        return [
            'project' => ProjectFactory::new(),
            'name' => 'Bug',
            'workflowId' => 'bug',
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::withConstructor()->alwaysForce());
    }
}

<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Tracker;
use App\Entity\Workflow;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\Persistence\repository;

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
        // `workflowId` is given as a key, as everywhere: the row it names must exist.
        return $this
            ->beforeInstantiate(static function (array $attributes): array {
                $attributes['workflow'] = repository(Workflow::class)->find($attributes['workflowId']) ?? throw new \LogicException(\sprintf('No workflow "%s" in the configuration.', $attributes['workflowId']));
                unset($attributes['workflowId']);

                return $attributes;
            })
            ->instantiateWith(Instantiator::withConstructor()->alwaysForce());
    }
}

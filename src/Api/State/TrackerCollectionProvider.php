<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Mapper\TrackerMapper;
use App\Api\Resource\TrackerResource;
use App\Message\Query\Project\ListTrackers;
use App\Message\QueryBus;
use App\Security\Viewer;

/**
 * @implements ProviderInterface<TrackerResource>
 */
final class TrackerCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly Lookup $lookup,
        private readonly TrackerMapper $trackers,
    ) {
    }

    /**
     * @return list<TrackerResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $project = $this->lookup->project((string) $uriVariables['key']);

        return array_map($this->trackers->map(...), $this->queries->ask(new ListTrackers($this->viewer->get(), $project)));
    }
}

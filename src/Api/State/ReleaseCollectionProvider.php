<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Resource\ReleaseResource;
use App\Message\Query\Release\ListReleases;
use App\Message\QueryBus;
use App\Security\Viewer;

/**
 * @implements ProviderInterface<ReleaseResource>
 */
final class ReleaseCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly Lookup $lookup,
    ) {
    }

    /**
     * @return list<ReleaseResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $project = $this->lookup->project((string) $uriVariables['key']);

        return array_map(ReleaseResource::of(...), $this->queries->ask(new ListReleases($this->viewer->get(), $project)));
    }
}

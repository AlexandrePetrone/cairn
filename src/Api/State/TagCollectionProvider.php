<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Resource\TagResource;
use App\Message\Query\Tag\ListTags;
use App\Message\QueryBus;

/**
 * Whoever sees the project reads its tags: none of them is private.
 *
 * @implements ProviderInterface<TagResource>
 */
final class TagCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly Lookup $lookup,
    ) {
    }

    /**
     * @return list<TagResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(TagResource::of(...), $this->queries->ask(new ListTags($this->lookup->project((string) $uriVariables['key']))));
    }
}

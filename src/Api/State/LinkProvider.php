<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Mapper\LinkMapper;
use App\Api\Resource\LinkResource;

/**
 * The link a DELETE names, read from its source.
 *
 * @implements ProviderInterface<LinkResource>
 */
final class LinkProvider implements ProviderInterface
{
    public function __construct(
        private readonly Lookup $lookup,
        private readonly LinkMapper $links,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LinkResource
    {
        $link = $this->lookup->link((int) $uriVariables['id']);

        return $this->links->map($link, $link->getSource());
    }
}

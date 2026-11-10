<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Mapper\LinkMapper;
use App\Api\Resource\LinkResource;
use App\Entity\IssueLink;
use App\Message\Query\Issue\ListLinks;
use App\Message\QueryBus;
use App\Security\Viewer;

/**
 * @implements ProviderInterface<LinkResource>
 */
final class LinkCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly Lookup $lookup,
        private readonly LinkMapper $links,
    ) {
    }

    /**
     * @return list<LinkResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $issue = $this->lookup->issue((string) $uriVariables['key']);

        return array_map(
            fn (IssueLink $link): LinkResource => $this->links->map($link, $issue),
            $this->queries->ask(new ListLinks($this->viewer->get(), $issue)),
        );
    }
}

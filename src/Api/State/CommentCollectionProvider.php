<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Mapper\CommentMapper;
use App\Api\Resource\CommentResource;

/**
 * @implements ProviderInterface<CommentResource>
 */
final class CommentCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Lookup $lookup,
        private readonly CommentMapper $comments,
    ) {
    }

    /**
     * @return list<CommentResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return $this->comments->all($this->lookup->detail((string) $uriVariables['key']));
    }
}

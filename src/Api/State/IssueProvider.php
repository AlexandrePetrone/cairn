<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Mapper\IssueMapper;
use App\Api\Resource\IssueResource;

/**
 * @implements ProviderInterface<IssueResource>
 */
final class IssueProvider implements ProviderInterface
{
    public function __construct(
        private readonly Lookup $lookup,
        private readonly IssueMapper $issues,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): IssueResource
    {
        return $this->issues->detail($this->lookup->detail((string) $uriVariables['key']));
    }
}

<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Mapper\Refs;
use App\Api\Resource\AttachmentResource;

/**
 * @implements ProviderInterface<AttachmentResource>
 */
final class AttachmentProvider implements ProviderInterface
{
    public function __construct(
        private readonly Lookup $lookup,
        private readonly Refs $refs,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AttachmentResource
    {
        return AttachmentResource::of($this->refs->file($this->lookup->attachment((string) $uriVariables['uuid'])));
    }
}

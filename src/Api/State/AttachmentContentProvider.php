<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Attachment\AttachmentStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /attachments/{uuid}/content: the file as the application serves
 * it. A response is sent as it is, nothing is serialized.
 *
 * @implements ProviderInterface<BinaryFileResponse>
 */
final class AttachmentContentProvider implements ProviderInterface
{
    public function __construct(
        private readonly Lookup $lookup,
        private readonly AttachmentStorage $storage,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BinaryFileResponse
    {
        return $this->storage->serve($this->lookup->attachment((string) $uriVariables['uuid']));
    }
}

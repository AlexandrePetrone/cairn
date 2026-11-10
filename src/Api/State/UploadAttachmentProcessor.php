<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mapper\Refs;
use App\Api\Resource\AttachmentResource;
use App\Entity\Attachment;
use App\Message\Command\Attachment\UploadAttachment;
use App\Security\Viewer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * POST /projects/{key}/attachments: the request is not deserialized, the
 * file is its `file` part.
 *
 * @implements ProcessorInterface<mixed, AttachmentResource>
 */
final class UploadAttachmentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CommandDispatcher $commands,
        private readonly Viewer $viewer,
        private readonly Lookup $lookup,
        private readonly Refs $refs,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AttachmentResource
    {
        $project = $this->lookup->project((string) $uriVariables['key']);
        $request = $context['request'] ?? null;
        $file = $request instanceof Request ? $request->files->get('file') : null;
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('file: a file is expected in the `file` part of a multipart form.');
        }

        $attachment = $this->commands->dispatch(new UploadAttachment((int) $this->viewer->get()->getId(), (int) $project->getId(), $file));
        \assert($attachment instanceof Attachment);

        return AttachmentResource::of($this->refs->file($attachment));
    }
}

<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Resource\CommentResource;
use App\Message\Command\Issue\DeleteComment;
use App\Security\Viewer;

/**
 * @implements ProcessorInterface<CommentResource, null>
 */
final class DeleteCommentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CommandDispatcher $commands,
        private readonly Viewer $viewer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->commands->dispatch(new DeleteComment((int) $this->viewer->get()->getId(), (int) $uriVariables['id']));

        return null;
    }
}

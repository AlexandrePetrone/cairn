<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Resource\CommentInput;
use App\Api\Resource\CommentResource;
use App\Message\Command\Issue\EditComment;
use App\Security\Viewer;

/**
 * @implements ProcessorInterface<CommentInput, CommentResource>
 */
final class EditCommentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CommandDispatcher $commands,
        private readonly Viewer $viewer,
        private readonly CommentProvider $comments,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommentResource
    {
        \assert($data instanceof CommentInput);
        $id = (int) $uriVariables['id'];
        $this->commands->dispatch(new EditComment((int) $this->viewer->get()->getId(), $id, (string) $data->body, $data->attachments));

        return $this->comments->one($id);
    }
}

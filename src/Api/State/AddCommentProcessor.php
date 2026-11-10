<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Resource\CommentInput;
use App\Api\Resource\CommentResource;
use App\Entity\Comment;
use App\Message\Command\Issue\AddComment;
use App\Security\Viewer;

/**
 * @implements ProcessorInterface<CommentInput, CommentResource>
 */
final class AddCommentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CommandDispatcher $commands,
        private readonly Viewer $viewer,
        private readonly Lookup $lookup,
        private readonly CommentProvider $comments,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommentResource
    {
        \assert($data instanceof CommentInput);
        // Before the command, so that an issue the viewer may not see is a 404.
        $key = $this->lookup->issue((string) $uriVariables['key'])->getKey();

        $comment = $this->commands->dispatch(new AddComment((int) $this->viewer->get()->getId(), $key, (string) $data->body, $data->private, $data->attachments));
        \assert($comment instanceof Comment);

        return $this->comments->one((int) $comment->getId());
    }
}

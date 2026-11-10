<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Mapper\CommentMapper;
use App\Api\Resource\CommentResource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The comment a PATCH or a DELETE names, as its issue shows it to the
 * viewer.
 *
 * @implements ProviderInterface<CommentResource>
 */
final class CommentProvider implements ProviderInterface
{
    public function __construct(
        private readonly Lookup $lookup,
        private readonly CommentMapper $comments,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CommentResource
    {
        return $this->one((int) $uriVariables['id']);
    }

    public function one(int $id): CommentResource
    {
        $comment = $this->lookup->comment($id);
        foreach ($this->comments->all($this->lookup->detail($comment->getIssue()->getKey())) as $resource) {
            if ($resource->id === $id) {
                return $resource;
            }
        }

        throw new NotFoundHttpException(\sprintf('No comment %d.', $id));
    }
}

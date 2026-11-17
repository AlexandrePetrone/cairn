<?php

declare(strict_types=1);

namespace App\Api\Mcp\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mcp\AddCommentTool;
use App\Api\Resource\CommentInput;
use App\Api\Resource\CommentResource;
use App\Api\State\AddCommentProcessor as RestProcessor;

/**
 * @implements ProcessorInterface<AddCommentTool, CommentResource>
 */
final class AddCommentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RestProcessor $rest,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommentResource
    {
        \assert($data instanceof AddCommentTool);

        $input = new CommentInput();
        $input->body = $data->body;
        $input->private = $data->private;

        return $this->rest->process($input, $operation, ['key' => $data->key]);
    }
}

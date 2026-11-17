<?php

declare(strict_types=1);

namespace App\Api\Mcp\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mcp\MoveIssueTool;
use App\Api\Resource\IssueResource;
use App\Api\Resource\StepInput;
use App\Api\State\MoveIssueProcessor as RestProcessor;

/**
 * @implements ProcessorInterface<MoveIssueTool, IssueResource>
 */
final class MoveIssueProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RestProcessor $rest,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IssueResource
    {
        \assert($data instanceof MoveIssueTool);

        $input = new StepInput();
        $input->place = $data->place;

        return $this->rest->process($input, $operation, ['key' => $data->key]);
    }
}

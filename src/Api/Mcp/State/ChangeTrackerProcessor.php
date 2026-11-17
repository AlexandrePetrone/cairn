<?php

declare(strict_types=1);

namespace App\Api\Mcp\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mcp\ChangeTrackerTool;
use App\Api\Resource\IssueResource;
use App\Api\Resource\TrackerInput;
use App\Api\State\ChangeTrackerProcessor as RestProcessor;

/**
 * @implements ProcessorInterface<ChangeTrackerTool, IssueResource>
 */
final class ChangeTrackerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RestProcessor $rest,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IssueResource
    {
        \assert($data instanceof ChangeTrackerTool);

        $input = new TrackerInput();
        $input->tracker = $data->tracker;
        $input->place = $data->place;

        return $this->rest->process($input, $operation, ['key' => $data->key]);
    }
}

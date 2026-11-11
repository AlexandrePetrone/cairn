<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mapper\IssueMapper;
use App\Api\Resource\IssueResource;
use App\Api\Resource\StepInput;
use App\Message\Command\Issue\MoveIssue;
use App\Security\Viewer;

/**
 * @implements ProcessorInterface<StepInput, IssueResource>
 */
final class MoveIssueProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CommandDispatcher $commands,
        private readonly Viewer $viewer,
        private readonly Lookup $lookup,
        private readonly IssueMapper $issues,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IssueResource
    {
        \assert($data instanceof StepInput);
        // Before the command, so that an issue the viewer may not see is a 404.
        $issue = $this->lookup->issue((string) $uriVariables['key']);
        $key = $issue->getKey();
        // A PUT may be repeated: an issue already on the step is answered as it is.
        if ($issue->getPlace() === $data->place) {
            return $this->issues->detail($this->lookup->detail($key));
        }

        $this->commands->dispatch(new MoveIssue(
            actorId: (int) $this->viewer->get()->getId(),
            issueKey: $key,
            placeId: (string) $data->place,
        ));

        return $this->issues->detail($this->lookup->detail($key));
    }
}

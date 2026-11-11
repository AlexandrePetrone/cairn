<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mapper\IssueMapper;
use App\Api\Resource\IssueResource;
use App\Api\Resource\TrackerInput;
use App\Message\Command\Issue\ChangeTracker;
use App\Security\Viewer;

/**
 * @implements ProcessorInterface<TrackerInput, IssueResource>
 */
final class ChangeTrackerProcessor implements ProcessorInterface
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
        \assert($data instanceof TrackerInput);
        // Before the command, so that an issue the viewer may not see is a 404.
        $issue = $this->lookup->issue((string) $uriVariables['key']);
        $key = $issue->getKey();
        // A PUT may be repeated: an issue already in the tracker, on that step, is answered as it is.
        if ($issue->getTracker()->getId() === $data->tracker && $issue->getPlace() === $data->place) {
            return $this->issues->detail($this->lookup->detail($key));
        }

        $this->commands->dispatch(new ChangeTracker(
            actorId: (int) $this->viewer->get()->getId(),
            issueKey: $key,
            trackerId: (int) $data->tracker,
            placeId: (string) $data->place,
        ));

        return $this->issues->detail($this->lookup->detail($key));
    }
}

<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mapper\IssueMapper;
use App\Api\Resource\IssueResource;
use App\Api\Resource\TransitionInput;
use App\Message\Command\Issue\ApplyTransition;
use App\Security\Viewer;

/**
 * @implements ProcessorInterface<TransitionInput, IssueResource>
 */
final class ApplyTransitionProcessor implements ProcessorInterface
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
        \assert($data instanceof TransitionInput);
        // Before the command, so that an issue the viewer may not see is a 404.
        $key = $this->lookup->issue((string) $uriVariables['key'])->getKey();

        $this->commands->dispatch(new ApplyTransition(
            actorId: (int) $this->viewer->get()->getId(),
            issueKey: $key,
            transitionId: (string) $data->transition,
            comment: $data->comment,
            commentPrivate: $data->commentPrivate,
            attachments: $data->attachments,
        ));

        return $this->issues->detail($this->lookup->detail($key));
    }
}

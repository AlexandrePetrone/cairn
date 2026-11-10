<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mapper\LinkMapper;
use App\Api\Resource\LinkInput;
use App\Api\Resource\LinkResource;
use App\Entity\IssueLink;
use App\Message\Command\Issue\AddLink;
use App\Security\Viewer;

/**
 * @implements ProcessorInterface<LinkInput, LinkResource>
 */
final class AddLinkProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CommandDispatcher $commands,
        private readonly Viewer $viewer,
        private readonly Lookup $lookup,
        private readonly LinkMapper $links,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LinkResource
    {
        \assert($data instanceof LinkInput);
        // Before the command, so that an issue the viewer may not see is a 404.
        $issue = $this->lookup->issue((string) $uriVariables['key']);
        $actorId = (int) $this->viewer->get()->getId();
        $other = mb_strtoupper(trim((string) $data->issue));

        $link = $this->commands->dispatch($data->inverse
            ? new AddLink($actorId, $other, (string) $data->type, $issue->getKey())
            : new AddLink($actorId, $issue->getKey(), (string) $data->type, $other));
        \assert($link instanceof IssueLink);

        return $this->links->map($link, $issue);
    }
}

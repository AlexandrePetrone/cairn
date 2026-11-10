<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Resource\LinkResource;
use App\Message\Command\Issue\RemoveLink;
use App\Security\Viewer;

/**
 * @implements ProcessorInterface<LinkResource, null>
 */
final class RemoveLinkProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CommandDispatcher $commands,
        private readonly Viewer $viewer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->commands->dispatch(new RemoveLink((int) $this->viewer->get()->getId(), (int) $uriVariables['id']));

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Resource\SubscriptionResource;
use App\Message\Command\Issue\FollowIssue;
use App\Message\Command\Issue\UnfollowIssue;
use App\Security\Viewer;

/**
 * PUT follows the issue, DELETE stops following it.
 *
 * @implements ProcessorInterface<SubscriptionResource, SubscriptionResource|null>
 */
final class SubscriptionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CommandDispatcher $commands,
        private readonly Viewer $viewer,
        private readonly SubscriptionProvider $subscriptions,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?SubscriptionResource
    {
        $actorId = (int) $this->viewer->get()->getId();
        $key = (string) $uriVariables['issue'];
        if ($operation instanceof DeleteOperationInterface) {
            $this->commands->dispatch(new UnfollowIssue($actorId, $key));

            return null;
        }
        $this->commands->dispatch(new FollowIssue($actorId, $key));

        return $this->subscriptions->provide($operation, $uriVariables, $context);
    }
}

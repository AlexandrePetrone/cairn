<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Resource\SubscriptionResource;
use App\Repository\IssueSubscriptionRepository;
use App\Security\Viewer;

/**
 * @implements ProviderInterface<SubscriptionResource>
 */
final class SubscriptionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Lookup $lookup,
        private readonly Viewer $viewer,
        private readonly IssueSubscriptionRepository $subscriptions,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SubscriptionResource
    {
        $issue = $this->lookup->issue((string) $uriVariables['issue']);
        $subscription = $this->subscriptions->findOne($issue, $this->viewer->get());

        return new SubscriptionResource($issue->getKey(), null !== $subscription, $subscription?->getSince());
    }
}

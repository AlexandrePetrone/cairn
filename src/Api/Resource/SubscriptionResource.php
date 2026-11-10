<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Api\State\SubscriptionProcessor;
use App\Api\State\SubscriptionProvider;

/**
 * Whether the user follows an issue, that is receives a mail for what
 * happens to it. PUT follows, DELETE stops; both may be repeated.
 */
#[ApiResource(
    shortName: 'Subscription',
    operations: [
        new Get(uriTemplate: '/issues/{issue}/subscription', provider: SubscriptionProvider::class),
        new Put(
            uriTemplate: '/issues/{issue}/subscription',
            description: 'Follows the issue. Takes no body.',
            deserialize: false,
            validate: false,
            provider: SubscriptionProvider::class,
            processor: SubscriptionProcessor::class,
        ),
        new Delete(
            uriTemplate: '/issues/{issue}/subscription',
            description: 'Stops following the issue.',
            provider: SubscriptionProvider::class,
            processor: SubscriptionProcessor::class,
        ),
    ],
)]
final readonly class SubscriptionResource
{
    public function __construct(
        /** Key of the issue. */
        #[ApiProperty(identifier: true)]
        public string $issue,
        public bool $following,
        public ?\DateTimeImmutable $since = null,
    ) {
    }
}

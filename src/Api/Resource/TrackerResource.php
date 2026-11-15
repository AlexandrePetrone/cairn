<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Api\Resource\Value\PlaceRef;
use App\Api\Resource\Value\TrackerField;
use App\Api\Resource\Value\TrackerPlace;
use App\Api\State\TrackerCollectionProvider;

/**
 * A category of issues of a project, with what its workflow asks of an
 * issue: the steps it goes through and the fields it carries, which is
 * what creating one needs.
 */
#[ApiResource(
    shortName: 'Tracker',
    operations: [
        new GetCollection(
            uriTemplate: '/projects/{key}/trackers',
            uriVariables: ['key' => new Link(fromClass: ProjectResource::class)],
            description: 'The categories of the project that take new issues, in display order.',
            paginationEnabled: false,
            provider: TrackerCollectionProvider::class,
        ),
    ],
)]
final readonly class TrackerResource
{
    /**
     * @param list<TrackerPlace> $places
     * @param list<TrackerField> $fields
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        public string $name,
        public ?string $description,
        /** Its issues are for internal users. */
        public bool $private,
        public PlaceRef $workflow,
        public array $places,
        public array $fields,
    ) {
    }
}

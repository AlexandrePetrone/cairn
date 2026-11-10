<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Api\State\ReleaseCollectionProvider;
use App\Message\Query\Release\ReleaseSummary;

/**
 * A planned delivery of a project, with its progress over the issues the
 * user sees. Only an open release takes issues.
 */
#[ApiResource(
    shortName: 'Release',
    operations: [
        new GetCollection(
            uriTemplate: '/projects/{key}/releases',
            uriVariables: ['key' => new Link(fromClass: ProjectResource::class)],
            description: 'The releases of the project: the open ones by planned date, then the others, latest first.',
            paginationEnabled: false,
            provider: ReleaseCollectionProvider::class,
        ),
    ],
)]
final readonly class ReleaseResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        public string $name,
        public ?string $description,
        /** `open`, `delivered` or `abandoned`. */
        public string $status,
        public ?\DateTimeImmutable $plannedOn,
        public ?\DateTimeImmutable $deliveredAt,
        public int $issues,
        /** Share of its issues that are finished, 0 to 100. */
        public int $progress,
    ) {
    }

    public static function of(ReleaseSummary $summary): self
    {
        $release = $summary->release;

        return new self((int) $release->getId(), $release->getName(), $release->getDescription(), $release->getStatus()->value, $release->getPlannedOn(), $release->getDeliveredAt(), $summary->total(), $summary->progress());
    }
}

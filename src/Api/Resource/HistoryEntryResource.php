<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Api\Resource\Value\UserRef;
use App\Api\State\HistoryProvider;

/**
 * A line of the history of an issue: what happened, said as the issue
 * page says it, with the labels of the time.
 */
#[ApiResource(
    shortName: 'HistoryEntry',
    operations: [
        new GetCollection(
            uriTemplate: '/issues/{key}/history',
            uriVariables: ['key' => new Link(fromClass: IssueResource::class)],
            description: 'What happened to the issue that the user may know of, oldest first.',
            paginationEnabled: false,
            provider: HistoryProvider::class,
        ),
    ],
)]
final readonly class HistoryEntryResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        /** Key of the issue. */
        public string $issue,
        /** `created`, `transitioned`, `changed`, `linked`, `commits_pushed`… */
        public string $type,
        /** The sentence of the issue page, in the language of the instance. */
        public string $label,
        public \DateTimeImmutable $at,
        /** Nobody when a forge wrote the entry, or an automation rule (`rule` then names it). */
        public ?UserRef $author,
        public bool $private,
        /** Id of the comment that came with it, among those of GET /issues/{key}/comments. */
        public ?int $comment = null,
        /** The name of the automation rule that wrote the entry. */
        public ?string $rule = null,
    ) {
    }
}

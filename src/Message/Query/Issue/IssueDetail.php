<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Config\Definition\PlaceDefinition;
use App\Config\FieldType;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\JournalEntry;

/**
 * The issue page: the entity itself, plus what no entity carries, computed
 * for the viewer.
 */
final readonly class IssueDetail
{
    /**
     * @param PlaceDefinition|null                                                         $place    the current place, null when it left the configuration
     * @param list<array{at: \DateTimeImmutable, comment: ?Comment, event: ?JournalEntry}> $timeline comments and journal entries the viewer may see, oldest first; a comment that came with an action appears once, with its entry
     * @param list<array{label: string, type: FieldType, value: mixed}>                    $fields   the filled-in fields of the workflow, option labels resolved
     */
    public function __construct(
        public Issue $issue,
        public ?PlaceDefinition $place,
        public array $timeline,
        public array $fields,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Message\Command\Import;

use App\Entity\Enum\Criticality;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Writes one issue of an import as it stood in its archive, in one
 * transaction: the issue on its place, on top of its kanban column, with
 * its dates and properties, its comments and files with their authors
 * and dates, the subscriptions of its author, assignee and watchers, and
 * the journal line that says where it came from. The import resolved
 * every id, under its own rules; nothing here asks for a right, and
 * nothing notifies: no domain event is dispatched, so no mail leaves and
 * no page is told. The texts are in Cairn's syntax but for their
 * `{{file:ID}}` tokens, which the handler turns into the addresses of
 * the attachments. Handled by ImportIssueHandler, which returns the Issue.
 */
final readonly class ImportIssue
{
    /**
     * @param array<string, mixed>  $fieldValues
     * @param list<ImportedComment> $comments    oldest first
     * @param list<ImportedFile>    $files       the files of the issue itself
     * @param list<int>             $watcherIds
     * @param list<string>          $notes       what was not kept, for the journal line
     */
    public function __construct(
        public int $projectId,
        public int $trackerId,
        public int $number,
        public string $placeId,
        public int $authorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 200)]
        public string $title,
        public string $description,
        public Criticality $criticality,
        public ?int $assigneeId,
        public ?int $releaseId,
        public bool $private,
        public array $fieldValues,
        public string $source,
        #[Assert\Length(max: 60)]
        public string $importedRef,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $closedAt,
        public array $comments = [],
        public array $files = [],
        public array $watcherIds = [],
        public array $notes = [],
    ) {
    }
}

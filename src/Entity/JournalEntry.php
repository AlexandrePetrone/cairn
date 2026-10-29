<?php

declare(strict_types=1);

namespace App\Entity;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Enum\JournalEntryType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * One immutable line of an issue's history. It keeps the labels of the
 * time, so that renaming a place or a tracker later never rewrites the
 * past. Private entries are hidden from external users.
 */
#[ORM\Entity]
class JournalEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Issue $issue;

    /** Null when the system acted (a webhook, a migration). */
    #[ORM\ManyToOne]
    private ?User $author;

    #[ORM\Column]
    private \DateTimeImmutable $at;

    #[ORM\Column(length: 30, enumType: JournalEntryType::class)]
    private JournalEntryType $type;

    #[ORM\Column]
    private bool $private;

    /** @var array<string, mixed> labels and ids of the time */
    #[ORM\Column(type: Types::JSON)]
    private array $data;

    /** The comment that came with the action, if any. */
    #[ORM\ManyToOne]
    private ?Comment $comment;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(Issue $issue, ?User $author, JournalEntryType $type, array $data, bool $private = false, ?Comment $comment = null)
    {
        $this->issue = $issue;
        $this->author = $author;
        $this->type = $type;
        $this->data = $data;
        $this->private = $private;
        $this->comment = $comment;
        $this->at = now();
    }

    public static function created(Issue $issue, User $author): self
    {
        return new self($issue, $author, JournalEntryType::Created, [
            'tracker' => $issue->getTracker()->getName(),
            'place' => $issue->getPlace(),
        ]);
    }

    public static function transitioned(Issue $issue, User $author, string $transitionLabel, PlaceDefinition $from, PlaceDefinition $to, ?Comment $comment = null): self
    {
        return new self($issue, $author, JournalEntryType::Transitioned, [
            'transition' => $transitionLabel,
            'from' => $from->label,
            'from_id' => $from->id,
            'to' => $to->label,
            'to_id' => $to->id,
        ], $comment?->isPrivate() ?? false, $comment);
    }

    public static function trackerChanged(Issue $issue, User $author, Tracker $from, PlaceDefinition $fromPlace, Tracker $to, PlaceDefinition $toPlace): self
    {
        return new self($issue, $author, JournalEntryType::TrackerChanged, [
            'from_tracker' => $from->getName(),
            'from' => $fromPlace->label,
            'from_id' => $fromPlace->id,
            'to_tracker' => $to->getName(),
            'to' => $toPlace->label,
            'to_id' => $toPlace->id,
        ]);
    }

    /**
     * A property of the issue changed: `assignee` (full names), `criticality`
     * (enum values) or `visibility` (`public`, `private`). Null is "none".
     */
    public static function changed(Issue $issue, User $author, string $field, ?string $from, ?string $to): self
    {
        return new self($issue, $author, JournalEntryType::Changed, [
            'field' => $field,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function getType(): JournalEntryType
    {
        return $this->type;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getComment(): ?Comment
    {
        return $this->comment;
    }
}

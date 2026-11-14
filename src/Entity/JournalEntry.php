<?php

declare(strict_types=1);

namespace App\Entity;

use App\Config\Definition\FieldDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Entity\Enum\JournalEntryType;
use App\Repository\JournalEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * One immutable line of an issue's history. It keeps the labels of the
 * time, so that renaming a place or a tracker later never rewrites the
 * past. Private entries are hidden from external users.
 */
#[ORM\Entity(repositoryClass: JournalEntryRepository::class)]
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

    /** The issue was put on a place without an action of the workflow. */
    public static function moved(Issue $issue, User $author, PlaceDefinition $from, PlaceDefinition $to): self
    {
        return new self($issue, $author, JournalEntryType::Moved, [
            'from' => $from->label,
            'from_id' => $from->id,
            'to' => $to->label,
            'to_id' => $to->id,
        ]);
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
     * (enum values), `visibility` (`public`, `private`), `title` (the
     * titles) or `description` (both texts). Null is "none".
     */
    public static function changed(Issue $issue, User $author, string $field, ?string $from, ?string $to): self
    {
        return new self($issue, $author, JournalEntryType::Changed, [
            'field' => $field,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * A comment was rewritten: both versions are kept, with the name of
     * the comment's author. Private when the comment is. Not linked to the
     * comment: a linked comment is shown with its entry, at the entry's
     * date.
     */
    public static function commentEdited(Issue $issue, User $author, Comment $comment, string $from, string $to): self
    {
        return new self($issue, $author, JournalEntryType::CommentEdited, [
            'from' => $from,
            'to' => $to,
            'author' => $comment->getAuthor()->getFullName(),
            'author_id' => $comment->getAuthor()->getId(),
        ], $comment->isPrivate());
    }

    /**
     * A comment was deleted: its text, author and date live on here.
     * Private when the comment was. Not linked to the comment, which goes.
     */
    public static function commentDeleted(Issue $issue, User $author, Comment $comment): self
    {
        return new self($issue, $author, JournalEntryType::CommentDeleted, [
            'body' => $comment->getBody(),
            'author' => $comment->getAuthor()->getFullName(),
            'author_id' => $comment->getAuthor()->getId(),
            'at' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $comment->isPrivate());
    }

    /** The comment this entry came with is gone; the entry stays. */
    public function detachComment(): void
    {
        $this->comment = null;
    }

    /**
     * A field of the workflow changed, with its label and the values as
     * they were displayed. Private when the field is for internal users only.
     */
    public static function fieldChanged(Issue $issue, User $author, FieldDefinition $field, mixed $from, mixed $to): self
    {
        return new self($issue, $author, JournalEntryType::FieldChanged, [
            'field' => $field->id,
            'label' => $field->label,
            'from' => self::scalar($field->display($from)),
            'to' => self::scalar($field->display($to)),
        ], $field->internalOnly);
    }

    /**
     * A file was added to the issue itself; the files of a comment come
     * with the comment and need no entry.
     */
    public static function attachmentAdded(Issue $issue, User $author, Attachment $attachment): self
    {
        return new self($issue, $author, JournalEntryType::AttachmentAdded, ['filename' => $attachment->getFilename()]);
    }

    /**
     * A file was removed, and deleted: the entry keeps its name. Private
     * when the file came with a private comment.
     */
    public static function attachmentRemoved(Issue $issue, User $author, Attachment $attachment): self
    {
        return new self($issue, $author, JournalEntryType::AttachmentRemoved, ['filename' => $attachment->getFilename()], $attachment->getComment()?->isPrivate() ?? false);
    }

    /**
     * The issue was linked to another one, or unlinked: the entry keeps the
     * label read from this issue and the key and title of the other one.
     * Private when the other issue may be hidden from the readers of this
     * one: a private issue, or one of another project.
     */
    public static function linked(Issue $issue, User $author, string $label, Issue $other, bool $removed = false): self
    {
        return new self($issue, $author, $removed ? JournalEntryType::Unlinked : JournalEntryType::Linked, [
            'label' => $label,
            'key' => $other->getKey(),
            'title' => $other->getTitle(),
        ], $other->isPrivate() || $other->getTracker()->isPrivate() || $other->getProject() !== $issue->getProject());
    }

    /**
     * A forge delivered a push whose commits mention the issue: one entry
     * per push, listing the commits new to the issue, each with its short
     * sha, title, URL and author. No author: the forge acted. Private: the
     * code is the agency's.
     *
     * @param non-empty-list<CodeReference> $commits
     */
    public static function commitsPushed(Issue $issue, string $branch, string $branchUrl, array $commits): self
    {
        $first = $commits[0];

        return new self($issue, null, JournalEntryType::CommitsPushed, [
            'forge' => $first->getForge()->value,
            'repository' => $first->getRepositoryPath(),
            'branch' => $branch,
            'url' => $branchUrl,
            'commits' => array_map(static fn (CodeReference $commit): array => [
                'sha' => $commit->getShortSha(),
                'title' => $commit->getTitle(),
                'url' => $commit->getUrl(),
                'author' => $commit->getAuthorName(),
            ], $commits),
        ], true);
    }

    /**
     * A merge request mentioning the issue was seen, or changed state: the
     * entry keeps what it looked like then. No author, private, as for
     * commits.
     */
    public static function mergeRequest(Issue $issue, CodeReference $mergeRequest, JournalEntryType $type): self
    {
        return new self($issue, null, $type, [
            'forge' => $mergeRequest->getForge()->value,
            'repository' => $mergeRequest->getRepositoryPath(),
            'number' => $mergeRequest->getExternalId(),
            'title' => $mergeRequest->getTitle(),
            'url' => $mergeRequest->getUrl(),
            'branch' => $mergeRequest->getBranch(),
            'author' => $mergeRequest->getAuthorName(),
            'state' => $mergeRequest->getState()?->value,
        ], true);
    }

    /**
     * The issue was imported: from which tracker, under which name there,
     * and what the import could not keep (`notes`, in the language of the
     * instance). No author: the import acted. Private: the old tracker is
     * the agency's.
     *
     * @param list<string> $notes
     */
    public static function imported(Issue $issue, string $source, string $ref, array $notes): self
    {
        return new self($issue, null, JournalEntryType::Imported, ['source' => $source, 'ref' => $ref, 'notes' => $notes], true);
    }

    private static function scalar(mixed $value): ?string
    {
        return match (true) {
            null === $value, '' === $value => null,
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) => (string) $value,
            default => json_encode($value, \JSON_THROW_ON_ERROR),
        };
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

    /** A recorded value as text: a label, an id, a body; `$default` when it is not there. */
    public function text(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? null;

        return \is_scalar($value) ? (string) $value : $default;
    }

    public function getComment(): ?Comment
    {
        return $this->comment;
    }
}

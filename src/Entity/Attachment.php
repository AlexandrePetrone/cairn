<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AttachmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * A file sent with an issue or a comment. It belongs to its project from
 * the upload and goes through three states: pending (no issue yet: being
 * written, seen by its uploader only), on an issue, or on a comment of
 * that issue. The command that writes the issue or the comment claims it.
 * Its random uuid names the file on disk and appears in the URL. Removing
 * an attachment deletes it, file and row: the one exception to keeping
 * everything, the journal keeps its name.
 */
#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
class Attachment
{
    public const array IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\ManyToOne]
    private ?Issue $issue;

    #[ORM\ManyToOne]
    private ?Comment $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $uploadedBy;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 100)]
    private string $mediaType;

    #[ORM\Column]
    private int $size;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $project, ?Issue $issue, User $uploadedBy, string $filename, string $mediaType, int $size)
    {
        $this->uuid = Uuid::v4();
        $this->project = $project;
        $this->issue = $issue;
        $this->uploadedBy = $uploadedBy;
        $this->filename = $filename;
        $this->mediaType = $mediaType;
        $this->size = $size;
        $this->createdAt = now();
    }

    /**
     * The row for a file a user sent, named and measured; storing the file
     * itself is AttachmentStorage's.
     */
    public static function fromUpload(UploadedFile $file, Project $project, ?Issue $issue, User $uploadedBy): self
    {
        return new self(
            $project,
            $issue,
            $uploadedBy,
            self::safeFilename($file->getClientOriginalName(), (string) $file->guessExtension()),
            (string) $file->getMimeType(),
            (int) $file->getSize(),
        );
    }

    /**
     * The row for a file brought from another tracker, at its date. It is
     * pending until attached to its issue or comment, as an upload is.
     */
    public static function imported(Project $project, User $uploadedBy, string $filename, string $mediaType, int $size, \DateTimeImmutable $createdAt): self
    {
        $attachment = new self($project, null, $uploadedBy, $filename, $mediaType, $size);
        $attachment->createdAt = $createdAt;

        return $attachment;
    }

    /**
     * A name safe in a URL, keeping the one the browser gave (a pasted
     * screenshot comes as `image.png`, an export keeps its `.log`), with
     * the guessed extension when the given one is missing or unusable.
     */
    public static function safeFilename(string $original, string $guessedExtension): string
    {
        $safe = static fn (string $part): string => trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $part), '-.');
        $name = mb_substr($safe(pathinfo($original, \PATHINFO_FILENAME)), 0, 100);
        $extension = strtolower($safe(pathinfo($original, \PATHINFO_EXTENSION)));
        if ('' === $extension || \strlen($extension) > 10) {
            $extension = '' === $guessedExtension ? 'bin' : $guessedExtension;
        }

        return ('' === $name ? 'file' : $name).'.'.$extension;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getIssue(): ?Issue
    {
        return $this->issue;
    }

    public function getComment(): ?Comment
    {
        return $this->comment;
    }

    public function isPending(): bool
    {
        return null === $this->issue;
    }

    /** Shown in the page; anything else is downloaded. */
    public function isImage(): bool
    {
        return \in_array($this->mediaType, self::IMAGE_TYPES, true);
    }

    public function getUploadedBy(): User
    {
        return $this->uploadedBy;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The attachments a text points to, through their URL
     * (`/attachments/<uuid>/<filename>`).
     *
     * @return list<Uuid>
     */
    public static function referencedIn(string $text): array
    {
        preg_match_all('#/attachments/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/#', $text, $matches);

        return array_values(array_map(static fn (string $uuid) => Uuid::fromString($uuid), array_unique($matches[1])));
    }

    public function attachTo(Issue $issue, ?Comment $comment = null): void
    {
        if ($issue->getProject() !== $this->project) {
            throw new \LogicException('An attachment stays in its project.');
        }
        if (null !== $comment && $comment->getIssue() !== $issue) {
            throw new \LogicException('An attachment goes with a comment of its issue.');
        }
        $this->issue = $issue;
        $this->comment = $comment;
    }
}

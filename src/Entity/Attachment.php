<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AttachmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * A file sent with a description or a comment, pasted images for now. It
 * belongs to its project from the upload and to its issue once known: an
 * image pasted while creating an issue is attached when the issue is
 * created. Its random uuid names the file on disk and appears in the URL.
 */
#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
class Attachment
{
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

    public function attachTo(Issue $issue): void
    {
        if ($issue->getProject() !== $this->project) {
            throw new \LogicException('An attachment stays in its project.');
        }
        $this->issue = $issue;
    }
}

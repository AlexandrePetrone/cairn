<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ReleaseStatus;
use App\Repository\ReleaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * A planned delivery of a project, which its issues are scheduled in, one
 * release per issue. Releases are optional: a project without any shows
 * none of it. Clients see them. Never deleted: an abandoned release keeps
 * its issues.
 */
#[ORM\Entity(repositoryClass: ReleaseRepository::class)]
#[ORM\Table(name: '`release`')]
#[ORM\UniqueConstraint(name: 'uniq_release_project_name', columns: ['project_id', 'name'])]
class Release
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\Column(length: 40)]
    private string $name;

    /** Shown to client users on the releases page. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $plannedOn = null;

    #[ORM\Column(length: 20, enumType: ReleaseStatus::class)]
    private ReleaseStatus $status = ReleaseStatus::Open;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $project, string $name)
    {
        $this->project = $project;
        $this->name = trim($name);
        $this->createdAt = now();
    }

    /** A release brought from another tracker, created at its date. */
    public static function imported(Project $project, string $name, \DateTimeImmutable $createdAt): self
    {
        $release = new self($project, $name);
        $release->createdAt = $createdAt;

        return $release;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function describe(?string $description): void
    {
        $this->description = '' === trim((string) $description) ? null : $description;
    }

    public function getPlannedOn(): ?\DateTimeImmutable
    {
        return $this->plannedOn;
    }

    public function plan(?\DateTimeImmutable $plannedOn): void
    {
        $this->plannedOn = $plannedOn?->setTime(0, 0);
    }

    public function getStatus(): ReleaseStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return ReleaseStatus::Open === $this->status;
    }

    /**
     * @param \DateTimeImmutable|null $at when it was delivered, now if not said (an imported release keeps its date)
     */
    public function deliver(?\DateTimeImmutable $at = null): void
    {
        $this->status = ReleaseStatus::Delivered;
        $this->deliveredAt = $at ?? now();
    }

    public function abandon(): void
    {
        $this->status = ReleaseStatus::Abandoned;
        $this->deliveredAt = null;
    }

    public function reopen(): void
    {
        $this->status = ReleaseStatus::Open;
        $this->deliveredAt = null;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

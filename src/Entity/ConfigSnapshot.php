<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConfigSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An applied instance configuration. The latest row is the one in force;
 * older rows are kept as the journal of configuration changes.
 */
#[ORM\Entity(repositoryClass: ConfigSnapshotRepository::class)]
#[ORM\Table(name: 'config_snapshot')]
class ConfigSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $hash;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $definition;

    #[ORM\Column(type: Types::TEXT)]
    private string $summary;

    #[ORM\Column]
    private \DateTimeImmutable $appliedAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $appliedBy;

    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(string $hash, array $definition, string $summary, ?string $appliedBy, \DateTimeImmutable $appliedAt = new \DateTimeImmutable())
    {
        $this->hash = $hash;
        $this->definition = $definition;
        $this->summary = $summary;
        $this->appliedBy = $appliedBy;
        $this->appliedAt = $appliedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefinition(): array
    {
        return $this->definition;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getAppliedAt(): \DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function getAppliedBy(): ?string
    {
        return $this->appliedBy;
    }
}

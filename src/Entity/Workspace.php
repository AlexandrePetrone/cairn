<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WorkspaceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * A group of projects, typically one per client. Memberships granted on a
 * workspace apply to every project in it. Its key is the first part of an
 * issue identifier (`MD-SPT-128`).
 */
#[ORM\Entity(repositoryClass: WorkspaceRepository::class)]
class Workspace
{
    public const string KEY_PATTERN = '/^[A-Z]{2,5}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 5, unique: true)]
    private string $key;

    /** Internal notes, never shown to external users. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $key)
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid workspace key (2 to 5 uppercase letters).', $key));
        }

        $this->name = trim($name);
        $this->key = $key;
        $this->createdAt = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = '' === trim((string) $notes) ? null : $notes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

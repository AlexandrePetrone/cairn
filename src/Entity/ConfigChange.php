<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConfigChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A change made to the configuration: when, by whom, and what it was, in
 * a sentence written then, with the names of the time. A trace to read,
 * never a version to go back to.
 */
#[ORM\Entity(repositoryClass: ConfigChangeRepository::class)]
#[ORM\Table(name: 'config_change')]
#[ORM\Index(name: 'idx_config_change_at', columns: ['changed_at'])]
class ConfigChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'changed_at')]
    private \DateTimeImmutable $at;

    /** The name of who made it, kept as text; null for what nobody signed in did (the installation, a console command). */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $summary;

    public function __construct(\DateTimeImmutable $at, ?string $author, string $summary)
    {
        $this->at = $at;
        $this->author = $author;
        $this->summary = $summary;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }
}

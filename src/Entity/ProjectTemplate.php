<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * What a new project may start with: trackers (TemplateTracker) and
 * automation rules (TemplateRule), written by the administrators and
 * copied into the project when it is created. Nothing links the two
 * afterwards: a template is configuration, changed and deleted freely,
 * and the projects made from it keep what they were given.
 */
#[ORM\Entity(repositoryClass: ProjectTemplateRepository::class)]
#[ORM\Table(name: 'project_template')]
#[ORM\UniqueConstraint(name: 'uniq_project_template_name', columns: ['name'])]
class ProjectTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    /** For the administrators: what kind of project it is meant for. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name)
    {
        $this->name = trim($name);
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = '' === trim((string) $description) ? null : $description;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

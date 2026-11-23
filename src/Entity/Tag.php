<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\TagColour;
use App\Repository\TagRepository;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * A tag of a project, which its issues carry, several per issue: a part
 * of the project (back office, front office) or anything its managers
 * sort issues by. Tags are optional: a project without any shows none of
 * it. External users see them. A tag is current state: deleting it takes
 * it off its issues, whose journal keeps the name.
 */
#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_tag_project_name', columns: ['project_id', 'name'])]
class Tag
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

    #[ORM\Column(length: 20, enumType: TagColour::class)]
    private TagColour $colour;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $project, string $name, TagColour $colour)
    {
        $this->project = $project;
        $this->name = trim($name);
        $this->colour = $colour;
        $this->createdAt = now();
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

    public function getColour(): TagColour
    {
        return $this->colour;
    }

    public function paint(TagColour $colour): void
    {
        $this->colour = $colour;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

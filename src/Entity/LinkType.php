<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LinkTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A type of link between two issues. Without an inverse label the link
 * reads the same from both sides. Its id is what links store.
 */
#[ORM\Entity(repositoryClass: LinkTypeRepository::class)]
#[ORM\Table(name: 'config_link_type')]
class LinkType implements Positioned
{
    #[ORM\Id]
    #[ORM\Column(length: 60)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $label;

    /** Label seen from the target issue. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $inverseLabel;

    #[ORM\Column]
    private int $position;

    public function __construct(string $id, string $label, ?string $inverseLabel = null, int $position = 0)
    {
        $this->id = $id;
        $this->label = trim($label);
        $this->setInverseLabel($inverseLabel);
        $this->position = $position;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getInverseLabel(): ?string
    {
        return $this->inverseLabel;
    }

    public function rename(string $label): void
    {
        $this->label = trim($label);
    }

    /**
     * @param string|null $inverseLabel null or blank for a link that reads the same from both sides
     */
    public function setInverseLabel(?string $inverseLabel): void
    {
        $this->inverseLabel = '' === trim((string) $inverseLabel) ? null : trim((string) $inverseLabel);
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }
}

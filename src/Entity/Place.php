<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\PlaceStatus;
use App\Repository\PlaceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A step issues may stand on, declared once for the instance: workflows
 * name the ones they use. Its id is what issues store, fixed once created;
 * the label may change freely. The order of the places is the order of the
 * filters and of the groups of the issue lists; the columns of a kanban
 * follow the workflow.
 */
#[ORM\Entity(repositoryClass: PlaceRepository::class)]
#[ORM\Table(name: 'config_place')]
class Place implements Positioned
{
    #[ORM\Id]
    #[ORM\Column(length: 60)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(length: 20, enumType: PlaceStatus::class)]
    private PlaceStatus $status;

    #[ORM\Column]
    private int $position;

    public function __construct(string $id, string $label, PlaceStatus $status, int $position = 0)
    {
        $this->id = $id;
        $this->label = trim($label);
        $this->status = $status;
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

    public function getStatus(): PlaceStatus
    {
        return $this->status;
    }

    public function rename(string $label): void
    {
        $this->label = trim($label);
    }

    /**
     * The issues standing on the place are open, waiting or closed by it:
     * nothing copies it, so nothing follows.
     */
    public function changeStatus(PlaceStatus $status): void
    {
        $this->status = $status;
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

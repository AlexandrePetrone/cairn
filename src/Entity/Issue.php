<?php

declare(strict_types=1);

namespace App\Entity;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Enum\Criticality;
use App\Entity\Enum\PlaceStatus;
use App\Repository\IssueRepository;
use App\Workflow\HasPlace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * The tracked object. Its key (`MD-SPT-128`) is fixed at creation. The
 * place, set from the first moment, is stored as the id of a workflow
 * place, with its status denormalised for sorting and filtering. Nothing
 * is ever deleted.
 */
#[ORM\Entity(repositoryClass: IssueRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_issue_project_number', columns: ['project_id', 'number'])]
#[ORM\Index(name: 'idx_issue_project_status', columns: ['project_id', 'status'])]
#[ORM\Index(name: 'idx_issue_assignee_status', columns: ['assignee_id', 'status'])]
class Issue implements HasPlace
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Tracker $tracker;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(name: '`key`', length: 20, unique: true)]
    private string $key;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(length: 60)]
    private string $place;

    #[ORM\Column(length: 20, enumType: PlaceStatus::class)]
    private PlaceStatus $status;

    #[ORM\Column(length: 20, enumType: Criticality::class)]
    private Criticality $criticality = Criticality::DEFAULT;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $author;

    #[ORM\ManyToOne]
    private ?User $assignee = null;

    #[ORM\Column]
    private bool $private = false;

    /** @var array<string, mixed> field id => value, including fields no longer used by the workflow */
    #[ORM\Column(type: Types::JSON)]
    private array $fieldValues = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /**
     * @param PlaceDefinition $place where the issue starts, the initial place of the tracker's workflow
     */
    public function __construct(Project $project, Tracker $tracker, PlaceDefinition $place, int $number, User $author, string $title, string $description)
    {
        $this->project = $project;
        $this->tracker = $tracker;
        $this->number = $number;
        $this->key = $project->getIssuePrefix().'-'.$number;
        $this->author = $author;
        $this->title = trim($title);
        $this->description = $description;
        $this->createdAt = now();
        $this->updatedAt = $this->createdAt;
        $this->moveTo($place);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getTracker(): Tracker
    {
        return $this->tracker;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPlace(): string
    {
        return $this->place;
    }

    public function getStatus(): PlaceStatus
    {
        return $this->status;
    }

    /**
     * Enters a place: the status follows, `closedAt` is set on entering a
     * closed place and cleared on leaving one.
     */
    public function moveTo(PlaceDefinition $place): void
    {
        $this->place = $place->id;
        $this->status = $place->status;
        $this->closedAt = PlaceStatus::Closed === $place->status ? ($this->closedAt ?? now()) : null;
        $this->touch();
    }

    /**
     * Re-categorises the issue: the workflow changes, so the place is chosen
     * explicitly. Values of fields the new workflow does not use are kept.
     */
    public function changeTracker(Tracker $tracker, PlaceDefinition $place): void
    {
        $this->tracker = $tracker;
        $this->moveTo($place);
    }

    public function getCriticality(): Criticality
    {
        return $this->criticality;
    }

    public function setCriticality(Criticality $criticality): void
    {
        $this->criticality = $criticality;
        $this->touch();
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function assignTo(?User $assignee): void
    {
        $this->assignee = $assignee;
        $this->touch();
    }

    /**
     * Hidden from external users, either by its own flag or because its
     * tracker is private.
     */
    public function isPrivate(): bool
    {
        return $this->private || $this->tracker->isPrivate();
    }

    public function makePrivate(): void
    {
        $this->private = true;
        $this->touch();
    }

    public function makePublic(): void
    {
        $this->private = false;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getFieldValues(): array
    {
        return $this->fieldValues;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setFieldValues(array $values): void
    {
        $this->fieldValues = $values;
        $this->touch();
    }

    /**
     * Merges changed values: null empties a field. Values of fields the
     * workflow no longer uses are kept.
     *
     * @param array<string, mixed> $changes field id => new value or null
     */
    public function changeFieldValues(array $changes): void
    {
        foreach ($changes as $fieldId => $value) {
            if (null === $value) {
                unset($this->fieldValues[$fieldId]);
            } else {
                $this->fieldValues[$fieldId] = $value;
            }
        }
        $this->touch();
    }

    public function retitle(string $title): void
    {
        $this->title = trim($title);
        $this->touch();
    }

    public function describe(string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    /**
     * Records activity on the issue (a comment, for instance).
     */
    public function touch(): void
    {
        $this->updatedAt = now();
    }
}

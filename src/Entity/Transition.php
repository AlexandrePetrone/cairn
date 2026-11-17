<?php

declare(strict_types=1);

namespace App\Entity;

use App\Config\TransitionStyle;
use App\Repository\TransitionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named action of a workflow, which moves an issue from one of its
 * source places (TransitionSource) to its target, shown as a button to the
 * roles it names (TransitionRole). Its key is what the journal, the API
 * and the automation rules name, unique within the workflow and fixed once
 * created.
 */
#[ORM\Entity(repositoryClass: TransitionRepository::class)]
#[ORM\Table(name: 'config_transition')]
#[ORM\UniqueConstraint(name: 'config_transition_key', columns: ['workflow_id', 'transition_key'])]
class Transition implements Positioned
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workflow $workflow;

    #[ORM\Column(name: 'transition_key', length: 60)]
    private string $key;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'target_id', nullable: false)]
    private Place $target;

    #[ORM\Column(length: 20, enumType: TransitionStyle::class)]
    private TransitionStyle $style;

    /** External users must comment when triggering it; internal users never have to. */
    #[ORM\Column]
    private bool $requiresComment;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentPrompt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column]
    private int $position;

    public function __construct(
        Workflow $workflow,
        string $key,
        string $label,
        Place $target,
        TransitionStyle $style = TransitionStyle::Default,
        bool $requiresComment = false,
        ?string $commentPrompt = null,
        ?string $description = null,
        int $position = 0,
    ) {
        $this->workflow = $workflow;
        $this->key = $key;
        $this->label = trim($label);
        $this->target = $target;
        $this->style = $style;
        $this->requireComment($requiresComment, $commentPrompt);
        $this->setDescription($description);
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflow(): Workflow
    {
        return $this->workflow;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getTarget(): Place
    {
        return $this->target;
    }

    public function getStyle(): TransitionStyle
    {
        return $this->style;
    }

    public function requiresComment(): bool
    {
        return $this->requiresComment;
    }

    public function getCommentPrompt(): ?string
    {
        return $this->commentPrompt;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function rename(string $label): void
    {
        $this->label = trim($label);
    }

    public function leadTo(Place $target): void
    {
        $this->target = $target;
    }

    public function restyle(TransitionStyle $style): void
    {
        $this->style = $style;
    }

    /**
     * @param string|null $prompt placeholder of the comment box; only kept when a comment is required
     */
    public function requireComment(bool $required, ?string $prompt): void
    {
        $this->requiresComment = $required;
        $this->commentPrompt = !$required || '' === trim((string) $prompt) ? null : $prompt;
    }

    public function setDescription(?string $description): void
    {
        $this->description = '' === trim((string) $description) ? null : $description;
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

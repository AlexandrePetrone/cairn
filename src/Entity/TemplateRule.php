<?php

declare(strict_types=1);

namespace App\Entity;

use App\Automation\Definition\RuleDefinition;
use App\Repository\TemplateRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An automation rule a project template gives the projects made from it:
 * what an AutomationRule says, limited to a tracker of the template or
 * holding on all of them. It names roles, never people, so it is copied
 * as it is; it runs nowhere while it is in the template.
 */
#[ORM\Entity(repositoryClass: TemplateRuleRepository::class)]
#[ORM\Table(name: 'template_rule')]
class TemplateRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ProjectTemplate $template;

    #[ORM\Column(length: 120)]
    private string $name;

    /** Whether the rule is switched on in the projects made from the template. */
    #[ORM\Column]
    private bool $active = true;

    /** The tracker of the template the rule is limited to; all of them when null. */
    #[ORM\ManyToOne]
    private ?TemplateTracker $tracker;

    /** @var array<string, mixed> as RuleDefinition::toArray() gives it */
    #[ORM\Column(type: Types::JSON)]
    private array $definition;

    public function __construct(ProjectTemplate $template, string $name, ?TemplateTracker $tracker, RuleDefinition $definition)
    {
        $this->template = $template;
        $this->name = trim($name);
        $this->tracker = $tracker;
        $this->definition = $definition->toArray();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): ProjectTemplate
    {
        return $this->template;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function getTracker(): ?TemplateTracker
    {
        return $this->tracker;
    }

    public function getDefinition(): RuleDefinition
    {
        return RuleDefinition::fromArray($this->definition);
    }

    /**
     * What the rule says and where it holds change together: a step may
     * name a place of the tracker.
     */
    public function redefine(?TemplateTracker $tracker, RuleDefinition $definition): void
    {
        $this->tracker = $tracker;
        $this->definition = $definition->toArray();
    }
}

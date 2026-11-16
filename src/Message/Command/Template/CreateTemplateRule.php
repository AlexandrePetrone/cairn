<?php

declare(strict_types=1);

namespace App\Message\Command\Template;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Writes an automation rule in a project template: its name, the tracker
 * of the template it is limited to if any, and what it says, in the shape
 * of Automation\RuleDefinition::toArray(). Handled by
 * CreateTemplateRuleHandler, which checks the rule against the template
 * (Automation\RuleChecker: no person can be named there) and returns the
 * TemplateRule.
 */
final readonly class CreateTemplateRule
{
    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        public int $templateId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $name,
        public ?int $trackerId,
        public array $definition,
        public bool $active = true,
    ) {
    }
}

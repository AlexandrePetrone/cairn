<?php

declare(strict_types=1);

namespace App\Message\Command\Template;

/**
 * Takes a rule out of a project template. Handled by
 * DeleteTemplateRuleHandler.
 */
final readonly class DeleteTemplateRule
{
    public function __construct(
        public int $ruleId,
    ) {
    }
}

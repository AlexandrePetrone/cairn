<?php

declare(strict_types=1);

namespace App\Message\Command\Template;

/**
 * Deletes a project template with its trackers and its rules. The
 * projects made from it keep what they were given. Handled by
 * DeleteTemplateHandler.
 */
final readonly class DeleteTemplate
{
    public function __construct(
        public int $templateId,
    ) {
    }
}

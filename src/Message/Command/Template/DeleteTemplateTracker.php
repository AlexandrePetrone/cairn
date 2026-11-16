<?php

declare(strict_types=1);

namespace App\Message\Command\Template;

/**
 * Takes a tracker out of a project template, with the rules of the
 * template limited to it. Handled by DeleteTemplateTrackerHandler.
 */
final readonly class DeleteTemplateTracker
{
    public function __construct(
        public int $trackerId,
    ) {
    }
}

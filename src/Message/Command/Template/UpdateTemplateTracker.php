<?php

declare(strict_types=1);

namespace App\Message\Command\Template;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a tracker of a project template, PATCH style: a property left
 * null keeps its value, an empty description erases it, `position` moves
 * it among the others. Handled by UpdateTemplateTrackerHandler.
 */
final readonly class UpdateTemplateTracker
{
    public function __construct(
        public int $trackerId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $name = null,
        public ?string $description = null,
        public ?bool $private = null,
        #[Assert\PositiveOrZero]
        public ?int $position = null,
    ) {
    }
}

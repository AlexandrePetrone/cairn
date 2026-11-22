<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a role, PATCH style: a property left null keeps its value,
 * `position` moves it among the others (0 is the broadest). Handled by
 * UpdateRoleHandler.
 */
final readonly class UpdateRole implements ConfigurationCommand
{
    public function __construct(
        public string $id,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $label = null,
        public ?bool $internalOnly = null,
        public ?bool $followsNewIssues = null,
        public ?bool $freeWorkflow = null,
        #[Assert\PositiveOrZero]
        public ?int $position = null,
    ) {
    }
}

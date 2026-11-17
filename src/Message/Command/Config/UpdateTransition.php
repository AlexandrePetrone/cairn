<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use App\Config\TransitionStyle;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes an action of a workflow, PATCH style: a property left null keeps
 * its value, an empty prompt or description erases it, `from` and `roles`
 * replace the places it starts from and the roles that trigger it.
 * Handled by UpdateTransitionHandler.
 */
final readonly class UpdateTransition implements ConfigurationCommand
{
    public function __construct(
        public string $workflowId,
        public string $id,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $label = null,
        /** @var list<string>|null */
        #[Assert\Count(min: 1)]
        public ?array $from = null,
        public ?string $to = null,
        /** @var list<string>|null */
        #[Assert\Count(min: 1)]
        public ?array $roles = null,
        public ?TransitionStyle $style = null,
        public ?bool $requiresComment = null,
        #[Assert\Length(max: 255)]
        public ?string $commentPrompt = null,
        public ?string $description = null,
        #[Assert\PositiveOrZero]
        public ?int $position = null,
    ) {
    }
}

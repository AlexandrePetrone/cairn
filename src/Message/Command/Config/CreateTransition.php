<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use App\Config\TransitionStyle;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Adds an action to a workflow: the button that moves an issue from one of
 * the `from` places to the `to` place, for the roles named. Its key is
 * unique within the workflow, for good. Handled by CreateTransitionHandler,
 * which returns the Transition.
 */
final readonly class CreateTransition
{
    public function __construct(
        public string $workflowId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $label,
        /** @var list<string> */
        #[Assert\Count(min: 1)]
        public array $from,
        #[Assert\NotBlank]
        public string $to,
        /** @var list<string> */
        #[Assert\Count(min: 1)]
        public array $roles,
        public TransitionStyle $style = TransitionStyle::Default,
        public bool $requiresComment = false,
        #[Assert\Length(max: 255)]
        public ?string $commentPrompt = null,
        public ?string $description = null,
        public ?string $id = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Config\Definition;

use App\Config\TransitionStyle;

/**
 * A named action that moves an issue from one of the `from` places to the
 * `to` place. Shown as a button to the roles listed.
 */
final readonly class TransitionDefinition
{
    /**
     * @param list<string> $from            places the transition may be triggered from
     * @param list<string> $roles           roles allowed to trigger the transition
     * @param bool         $requiresComment external users must comment when triggering it; internal users never have to
     * @param string|null  $commentPrompt   placeholder of the comment box when a comment is required of them
     * @param string|null  $description     what the action does, shown under it on the issue page
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $from,
        public string $to,
        public array $roles,
        public TransitionStyle $style = TransitionStyle::Default,
        public bool $requiresComment = false,
        public ?string $commentPrompt = null,
        public ?string $description = null,
    ) {
    }
}

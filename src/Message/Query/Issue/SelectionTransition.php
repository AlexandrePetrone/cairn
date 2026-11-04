<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Config\TransitionStyle;

/**
 * A workflow action every issue of a selection may take. Transitions are
 * matched by id across workflows; the label joins those that differ, a
 * comment is required as soon as one of them requires it.
 */
final readonly class SelectionTransition
{
    public function __construct(
        public string $id,
        public string $label,
        public TransitionStyle $style,
        public bool $requiresComment,
        public ?string $commentPrompt,
    ) {
    }
}

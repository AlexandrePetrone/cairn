<?php

declare(strict_types=1);

namespace App\Tests\Automation;

use App\Event\CommentAdded;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A listener that breaks on the comments of the rule named RULE, as a bug
 * would. Declared in the container of the test environment: the compiled
 * dispatcher takes no listener at runtime.
 */
#[AsEventListener]
final class BrokenCommentListener
{
    public const string RULE = 'Breaks a listener';

    public function __invoke(CommentAdded $event): void
    {
        if (self::RULE === $event->rule) {
            throw new \LogicException('A listener broke.');
        }
    }
}

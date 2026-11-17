<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Message\Command\Config\ConfigurationCommand;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Runs the commands that change the configuration one at a time. A
 * handler reads the configuration, checks the change against it, then
 * writes: two administrators at once could each pass a check the other's
 * change makes false (one takes a step out of a workflow while the other
 * adds an action that leads to it). The lock is held around the
 * transaction of the command, so the second reads what the first wrote.
 *
 * It does not hold an issue command back: someone may move an issue to a
 * step in the instant it leaves its workflow, which the application
 * tolerates and an internal user repairs.
 */
final class ConfigurationLock implements MiddlewareInterface
{
    private const string NAME = 'cairn.configuration';

    public function __construct(
        private readonly LockFactory $locks,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof ConfigurationCommand) {
            return $stack->next()->handle($envelope, $stack);
        }

        // The time a stuck process may hold it, for the stores that expire; a command takes far less.
        $lock = $this->locks->createLock(self::NAME, ttl: 60.0);
        $lock->acquire(blocking: true);
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $lock->release();
        }
    }
}

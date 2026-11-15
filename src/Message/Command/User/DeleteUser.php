<?php

declare(strict_types=1);

namespace App\Message\Command\User;

/**
 * Deletes an account that left no trace: created by mistake, or invited
 * and never used. Its access, its subscriptions, its tokens and its
 * picture go with it. An account that wrote or was given anything is
 * deactivated instead: its name stays on what it did. For the
 * administration only. Handled by DeleteUserHandler.
 */
final readonly class DeleteUser
{
    public function __construct(
        public int $userId,
    ) {
    }
}

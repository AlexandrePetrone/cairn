<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Entity\Issue;
use App\Entity\User;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Loading and visibility checks shared by the issue handlers.
 */
final class IssueHandlerSupport
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly IssueRepository $issues,
        private readonly AccessControl $access,
    ) {
    }

    public function actor(int $actorId): User
    {
        return $this->users->find($actorId) ?? throw IssueException::notFound('User', $actorId);
    }

    /**
     * The issue, if the actor may see it; an invisible issue is reported as
     * not found so that private issues cannot be probed.
     */
    public function visibleIssue(User $actor, string $key): Issue
    {
        $issue = $this->issues->findOneByKey($key);
        if (null === $issue || !$this->access->canSee($actor, $issue)) {
            throw IssueException::notFound('Issue', $key);
        }

        return $issue;
    }

    public function denyUnless(bool $granted, string $what): void
    {
        if (!$granted) {
            throw new AccessDeniedException(\sprintf('Not allowed to %s.', $what));
        }
    }
}

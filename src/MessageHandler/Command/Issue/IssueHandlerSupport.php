<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Config\Definition\WorkflowDefinition;
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

    /**
     * The values belong to the workflow and fill its required fields.
     *
     * @param array<string, mixed> $values
     */
    public function checkFields(WorkflowDefinition $workflow, array $values): void
    {
        foreach (array_keys($values) as $fieldId) {
            if (!isset($workflow->fields[$fieldId])) {
                throw IssueException::unknownField((string) $fieldId, $workflow->id);
            }
        }
        foreach ($workflow->fields as $usage) {
            $value = $values[$usage->field] ?? null;
            if ($usage->required && (null === $value || (\is_string($value) && '' === trim($value)))) {
                throw IssueException::missingField($usage->field);
            }
        }
    }

    public function denyUnless(bool $granted, string $what): void
    {
        if (!$granted) {
            throw new AccessDeniedException(\sprintf('Not allowed to %s.', $what));
        }
    }
}

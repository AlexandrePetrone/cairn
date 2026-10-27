<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Security\AccessControl;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Exposes AccessControl to `#[IsGranted]` and Twig. The attribute is `view`
 * or a permission value, the subject a project or a workspace:
 * `#[IsGranted('issue.create', subject: 'project')]`.
 *
 * @extends Voter<string, Project|Workspace>
 */
final class AccessVoter extends Voter
{
    public const string VIEW = 'view';

    public function __construct(
        private readonly AccessControl $accessControl,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return ($subject instanceof Project || $subject instanceof Workspace)
            && (self::VIEW === $attribute || null !== Permission::tryFrom($attribute));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return self::VIEW === $attribute
            ? $this->accessControl->canView($user, $subject)
            : $this->accessControl->isGranted($user, Permission::from($attribute), $subject);
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Message\Command\User\InviteUser;
use App\MessageHandler\Command\ProjectActor;
use App\Notification\AccountMailer;
use App\Repository\MembershipRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Security\PasswordTokens;
use App\Security\Permission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Only an active account without a password is invited: whoever has one
 * uses "Forgot password?". A manager invites from their project a direct
 * member of it. Issuing the token flushes (the bundle's doing, inside
 * the transaction of the bus) and the mail is queued on the same
 * connection: it leaves only if the command commits.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class InviteUserHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
        private readonly MembershipRepository $memberships,
        private readonly ProjectActor $actor,
        private readonly PasswordTokens $tokens,
        private readonly AccountMailer $mailer,
    ) {
    }

    public function __invoke(InviteUser $command): void
    {
        $user = $this->users->find($command->userId) ?? throw UserException::notFound($command->userId);

        if (null !== $command->actorId) {
            $project = null !== $command->projectId ? $this->projects->find($command->projectId) : null;
            $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectManageMembers, $project);
            \assert(null !== $project, 'denied above otherwise');
            if (null === $this->memberships->findOneBy(['user' => $user, 'project' => $project])) {
                throw UserException::notMemberOf($user->getFullName(), $project->getName());
            }
        }
        if (!$user->isActive()) {
            throw UserException::deactivated($user->getFullName());
        }
        if ($user->hasPassword()) {
            throw UserException::alreadyActivated($user->getFullName());
        }

        $this->mailer->sendInvitation($user, $this->tokens->issueInvitation($user));
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Entity\Enum\AuthMethod;
use App\Entity\User;
use App\Message\Command\User\CreateUser;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Security\Permission;
use App\Security\SingleSignOn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The email stays unique. A manager creating an account from their
 * project must hold `project.manage_members` there and creates an
 * external account only. A password is hashed when given; the account
 * is otherwise left to its invitation, or to the identity provider when
 * the administration says so and the instance has one.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class CreateUserHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
        private readonly ProjectActor $actor,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly SingleSignOn $sso,
    ) {
    }

    public function __invoke(CreateUser $command): User
    {
        if (null !== $command->actorId) {
            $project = null !== $command->projectId ? $this->projects->find($command->projectId) : null;
            $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectManageMembers, $project);
            if ($command->internal || $command->admin) {
                throw UserException::externalOnlyFromProject();
            }
            if (AuthMethod::Sso === $command->authMethod) {
                throw new AccessDeniedException('Only the administration tells an account to sign in through the identity provider.');
            }
        }
        if (AuthMethod::Sso === $command->authMethod && !$this->sso->enabled()) {
            throw UserException::ssoNotConfigured();
        }

        $email = mb_strtolower(trim($command->email));
        if (null !== $this->users->findOneBy(['email' => $email])) {
            throw UserException::emailTaken($email);
        }

        $user = new User($email, $command->firstName, $command->lastName, $command->internal || $command->admin, $command->company);
        if ($command->admin) {
            $user->makeInternal(admin: true);
        }
        if (null !== $command->plainPassword) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $command->plainPassword));
        }
        if (AuthMethod::Sso === $command->authMethod) {
            $user->signInWithSso();
        }
        if (!$command->active) {
            $user->deactivate();
        }

        $this->entityManager->persist($user);

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Entity\User;
use App\Message\Command\User\CreateUser;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The email stays unique. A manager creating an account from their
 * project must hold `project.manage_members` there and creates an
 * external account only. A password is hashed when given; the account
 * is otherwise left to its invitation.
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
        if (!$command->active) {
            $user->deactivate();
        }

        $this->entityManager->persist($user);

        return $user;
    }
}

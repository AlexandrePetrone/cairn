<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Entity\User;
use App\Message\Command\User\CreateUser;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateUserHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateUser $command): User
    {
        $email = mb_strtolower(trim($command->email));
        if (null !== $this->users->findOneBy(['email' => $email])) {
            throw UserException::emailTaken($email);
        }

        $user = new User($email, $command->firstName, $command->lastName, $command->internal || $command->admin);
        if ($command->admin) {
            $user->makeInternal(admin: true);
        }
        $user->setPassword($this->passwordHasher->hashPassword($user, $command->plainPassword));

        $this->entityManager->persist($user);

        return $user;
    }
}

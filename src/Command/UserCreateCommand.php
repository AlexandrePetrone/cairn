<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Message\CommandBus;
use App\Message\User\CreateUser;
use App\MessageHandler\User\UserException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\ValidationFailedException;

/**
 * Creates an account, typically the first administrator of an instance.
 * Everything is asked interactively; the password is typed twice, hidden.
 */
#[AsCommand(name: 'app:user:create', description: 'Create a user account (interactive)')]
final class UserCreateCommand
{
    private const array TYPES = ['external' => 'Externe (client)', 'internal' => 'Interne (agence)', 'admin' => 'Administrateur'];

    public function __construct(
        private readonly CommandBus $commands,
    ) {
    }

    public function __invoke(SymfonyStyle $io, InputInterface $input): int
    {
        if (!$input->isInteractive()) {
            $io->error('This command asks its questions interactively: run it without --no-interaction.');

            return Command::INVALID;
        }

        $email = (string) $io->ask('Email address (used to sign in)');
        $firstName = (string) $io->ask('First name');
        $lastName = (string) $io->ask('Last name');
        $type = $io->choice('Account type', self::TYPES, 'external');
        if (!\is_string($type) || !isset(self::TYPES[$type])) {
            throw new \LogicException('The choice question only returns one of the account types.');
        }
        $password = (string) $io->askHidden('Password (8 characters or more)');
        if ($password !== $io->askHidden('Password again')) {
            $io->error('The two passwords differ.');

            return Command::FAILURE;
        }

        try {
            $user = $this->commands->dispatch(new CreateUser($email, $firstName, $lastName, $password, internal: 'external' !== $type, admin: 'admin' === $type));
        } catch (ValidationFailedException $e) {
            foreach ($e->getViolations() as $violation) {
                $io->error(\sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()));
            }

            return Command::FAILURE;
        } catch (UserException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        \assert($user instanceof User);

        $io->success(\sprintf('%s created (%s).', $user->getEmail(), self::TYPES[$type]));

        return Command::SUCCESS;
    }
}

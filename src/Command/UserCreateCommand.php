<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Form\UserData;
use App\Message\Command\User\CreateUser;
use App\Message\CommandBus;
use App\MessageHandler\Command\User\UserException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creates an account, typically the first administrator of an instance,
 * before any mailer can send an invitation. Everything is asked
 * interactively; the password is typed twice, hidden. The
 * administration never asks one: it invites.
 */
#[AsCommand(name: 'app:user:create', description: 'Create a user account (interactive)')]
final class UserCreateCommand
{
    /** The account types and their label, as the administration offers them (Form/UserType). */
    private const array TYPES = [
        UserData::EXTERNAL => 'admin.user.type.external',
        UserData::INTERNAL => 'admin.user.type.internal',
        UserData::ADMIN => 'admin.user.type.admin',
    ];

    public function __construct(
        private readonly CommandBus $commands,
        private readonly TranslatorInterface $translator,
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
        $company = (string) $io->ask('Company (leave empty if none)');
        $types = array_map($this->translator->trans(...), self::TYPES);
        $type = $io->choice('Account type', $types, UserData::EXTERNAL);
        if (!\is_string($type) || !isset($types[$type])) {
            throw new \LogicException('The choice question only returns one of the account types.');
        }
        $password = (string) $io->askHidden('Password (8 characters or more)');
        if ($password !== $io->askHidden('Password again')) {
            $io->error('The two passwords differ.');

            return Command::FAILURE;
        }

        try {
            $user = $this->commands->dispatch(new CreateUser($email, $firstName, $lastName, internal: UserData::EXTERNAL !== $type, admin: UserData::ADMIN === $type, company: '' === $company ? null : $company, plainPassword: $password));
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

        $io->success(\sprintf('%s created (%s).', $user->getEmail(), $types[$type]));

        return Command::SUCCESS;
    }
}

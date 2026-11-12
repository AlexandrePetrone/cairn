<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\ProjectMailSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The Scheduler runs the same sending every thirty seconds; this is for a
 * run by hand, and `--now` for not waiting in development.
 */
#[AsCommand(name: 'app:notifications:send', description: 'Send the issue notifications that are due, grouped by person and project')]
final class NotificationsSendCommand extends Command
{
    public function __construct(
        private readonly ProjectMailSender $sender,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Send everything that waits, without waiting for things to calm down')]
        bool $now = false,
    ): int {
        $io->writeln(\sprintf('%d mail(s) sent.', $this->sender->sendDue($now)));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Automation\TimeRuleRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The Scheduler runs the rules that wait for time every hour; this is
 * for a run by hand.
 */
#[AsCommand(name: 'app:automations:run', description: 'Run the automation rules that wait for time on the issues they are due on')]
final class AutomationsRunCommand extends Command
{
    public function __construct(
        private readonly TimeRuleRunner $runner,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Count what is due without running anything')]
        bool $dryRun = false,
    ): int {
        $ran = $this->runner->runDue($dryRun);
        $io->success(\sprintf($dryRun ? '%d execution(s) due, nothing was run.' : '%d execution(s).', $ran));

        return Command::SUCCESS;
    }
}

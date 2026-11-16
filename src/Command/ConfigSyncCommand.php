<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\ConfigException;
use App\Config\ConfigLoader;
use App\Config\Sync\ConfigSynchronizer;
use App\Config\Sync\SyncPlan;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validates the configuration file, shows what changes against the snapshot
 * in force and applies it. Idempotent: running it twice applies nothing.
 *
 * Exit codes: 0 applied or up to date, 1 invalid file, 2 blocked by issues
 * sitting on removed places.
 */
#[AsCommand(
    name: 'app:config:sync',
    description: 'Validate the instance configuration file and apply it as a new snapshot',
)]
final class ConfigSyncCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $loader,
        private readonly ConfigSynchronizer $synchronizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without applying it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Instance configuration sync');
        $io->text(\sprintf('File: <info>%s</info>', $this->loader->path()));

        try {
            $plan = $this->synchronizer->plan($this->loader->load());
        } catch (ConfigException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->text(null === $plan->currentHash
            ? 'No snapshot applied yet: this will be the first one.'
            : \sprintf('Snapshot in force: <comment>%s</comment>', self::short($plan->currentHash)));

        if (!$plan->isValid()) {
            $io->section(\sprintf('%d violation(s)', \count($plan->violations)));
            $io->listing(array_map(strval(...), $plan->violations));
            $io->error('The configuration is invalid; nothing applied.');

            return Command::FAILURE;
        }

        if (!$plan->hasChanges()) {
            $io->success(\sprintf('Up to date (snapshot %s).', self::short($plan->hash)));

            return Command::SUCCESS;
        }

        $this->describe($io, $plan);

        if ($plan->isBlocked()) {
            $io->error('Some removed places still hold issues and no rule migrates them, or removed link types or workflows are still in use. Name the place that takes them in the `migrations` of their workflow or switch to the Fallback policy; keep a link type or a workflow still in use. Nothing applied.');

            return Command::INVALID;
        }

        if ($input->getOption('dry-run')) {
            $io->note(\sprintf('Dry run: snapshot %s not applied.', self::short($plan->hash)));

            return Command::SUCCESS;
        }

        try {
            $snapshot = $this->synchronizer->apply($plan);
        } catch (ConfigException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Snapshot #%d (%s) applied.', $snapshot->getId(), self::short($snapshot->getHash())));

        return Command::SUCCESS;
    }

    private function describe(SymfonyStyle $io, SyncPlan $plan): void
    {
        if ([] !== $plan->diff) {
            $io->section('Changes');
            $io->listing($plan->diff);
        } else {
            $io->text('The definition is unchanged; the snapshot is rewritten in the format of this version.');
        }

        if ([] !== $plan->migrations || [] !== $plan->statusChanges) {
            $io->section('Data migrations');
            $io->listing(array_map(strval(...), [...$plan->migrations, ...$plan->statusChanges]));
        }

        if ([] !== $plan->orphans) {
            $io->section('Removed places without migration target');
            $io->listing(array_map(strval(...), $plan->orphans));
        }

        if ([] !== $plan->usedLinkTypes) {
            $io->section('Removed link types still in use');
            $io->listing($plan->usedLinkTypes);
        }

        if ([] !== $plan->usedWorkflows) {
            $io->section('Removed workflows still in use');
            $io->listing($plan->usedWorkflows);
        }

        if ([] !== $plan->warnings) {
            $io->section('Warnings');
            $io->listing($plan->warnings);
        }
    }

    private static function short(string $hash): string
    {
        return substr($hash, 0, 12);
    }
}

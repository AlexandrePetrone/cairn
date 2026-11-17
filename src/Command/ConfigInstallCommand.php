<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\ConfigException;
use App\Config\StarterKit;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gives a new instance its starter configuration, in its language. Safe
 * to run again: an instance that has a configuration keeps it.
 */
#[AsCommand(
    name: 'app:config:install',
    description: 'Install the starter configuration (roles, workflows, fields) into an instance that has none',
)]
final class ConfigInstallCommand extends Command
{
    public function __construct(
        private readonly StarterKit $kit,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $installed = $this->kit->install();
        } catch (ConfigException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$installed) {
            $io->note('The instance already has a configuration: nothing changed. It is managed from the administration.');

            return Command::SUCCESS;
        }

        $io->success('Starter configuration installed. Change it under Administration > Configuration.');

        return Command::SUCCESS;
    }
}

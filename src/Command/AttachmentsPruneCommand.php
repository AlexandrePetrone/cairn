<?php

declare(strict_types=1);

namespace App\Command;

use App\Attachment\AttachmentPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The Scheduler runs the same pruning daily; this is for a run by hand.
 */
#[AsCommand(name: 'app:attachments:prune', description: 'Delete pending attachments nobody used and files without a row')]
final class AttachmentsPruneCommand extends Command
{
    public function __construct(
        private readonly AttachmentPruner $pruner,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Age from which a pending attachment is abandoned, as a date interval')]
        string $olderThan = '1 day',
        #[Option(description: 'Report without deleting anything')]
        bool $dryRun = false,
    ): int {
        $result = $this->pruner->prune($olderThan, $dryRun);

        $io->listing([
            \sprintf('%d pending attachment(s) older than %s%s', \count($result['rows']), $olderThan, $dryRun ? ' (kept)' : ' deleted'),
            \sprintf('%d file(s) without a row%s', \count($result['files']), $dryRun ? ' (kept)' : ' deleted'),
        ]);
        if ([] !== $result['missing']) {
            $io->warning(\sprintf('%d attachment(s) have no file on disk: %s', \count($result['missing']), implode(', ', $result['missing'])));
        }

        return Command::SUCCESS;
    }
}

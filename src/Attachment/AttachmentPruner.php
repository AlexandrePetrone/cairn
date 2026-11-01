<?php

declare(strict_types=1);

namespace App\Attachment;

use App\Repository\AttachmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Removes what no text ever took: pending attachments older than a day
 * (an upload abandoned with its draft) and files on disk without a row
 * (a transaction that failed after the move). Runs daily through the
 * Scheduler (`messenger:consume scheduler_default`), or by hand with
 * `app:attachments:prune`.
 */
final class AttachmentPruner
{
    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly AttachmentStorage $storage,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{rows: list<string>, files: list<string>, missing: list<string>} what was (or would be) deleted, and the rows whose file is gone
     */
    #[AsPeriodicTask('1 day', jitter: 3600)]
    public function prune(string $olderThan = '1 day', bool $dryRun = false): array
    {
        $before = $this->clock->now()->modify('-'.$olderThan);
        $rows = [];
        foreach ($this->attachments->findPendingBefore($before) as $attachment) {
            $rows[] = $attachment->getUuid()->toRfc4122();
            if (!$dryRun) {
                // The file goes with the row (AttachmentFileRemover).
                $this->entityManager->remove($attachment);
            }
        }
        if (!$dryRun) {
            $this->entityManager->flush();
        }

        // One query, then the rows and the files are compared in memory.
        $known = array_fill_keys($this->attachments->findAllUuids(), true);
        $present = [];
        $files = [];
        foreach ($this->storage->storedUuids() as $uuid) {
            $name = $uuid->toRfc4122();
            $present[$name] = true;
            if (isset($known[$name]) || filemtime($this->storage->pathOf($uuid)) >= $before->getTimestamp()) {
                continue;
            }
            $files[] = $name;
            if (!$dryRun) {
                unlink($this->storage->pathOf($uuid));
            }
        }

        $missing = array_values(array_filter(array_keys($known), static fn (string $name): bool => !isset($present[$name])));

        return ['rows' => $rows, 'files' => $files, 'missing' => $missing];
    }
}

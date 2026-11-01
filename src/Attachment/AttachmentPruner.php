<?php

declare(strict_types=1);

namespace App\Attachment;

use App\Repository\AttachmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;
use Symfony\Component\Uid\Uuid;

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
        #[Autowire('%env(resolve:ATTACHMENTS_DIR)%')]
        private readonly string $directory,
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

        $files = [];
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            $name = basename($path);
            if (!Uuid::isValid($name) || filemtime($path) >= $before->getTimestamp() || null !== $this->attachments->findOneByUuid(Uuid::fromString($name))) {
                continue;
            }
            $files[] = $name;
            if (!$dryRun) {
                unlink($path);
            }
        }

        $missing = [];
        foreach ($this->attachments->findAll() as $attachment) {
            if (!is_file($this->storage->path($attachment))) {
                $missing[] = $attachment->getUuid()->toRfc4122();
            }
        }

        return ['rows' => $rows, 'files' => $files, 'missing' => $missing];
    }
}

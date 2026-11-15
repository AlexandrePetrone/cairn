<?php

declare(strict_types=1);

namespace App\MessageHandler\Attachment;

use App\Attachment\AttachmentStorage;
use App\Import\ImportsDirectory;
use App\Message\Attachment\RemoveFiles;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * A file that cannot be removed is logged and left: the rows are gone
 * for good, and AttachmentPruner takes the attachment files without a
 * row the next day.
 */
#[AsMessageHandler]
final class RemoveFilesHandler
{
    public function __construct(
        private readonly AttachmentStorage $storage,
        private readonly ImportsDirectory $imports,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RemoveFiles $message): void
    {
        foreach ($message->attachments as $uuid) {
            $this->attempt(fn () => $this->storage->remove(Uuid::fromString($uuid)), $uuid);
        }
        foreach ($message->archives as $archive) {
            $this->attempt(fn () => $this->imports->discard($archive), $archive);
        }
        foreach ($message->imports as $import) {
            $this->attempt(fn () => $this->imports->clear($import), 'import '.$import);
        }
    }

    private function attempt(\Closure $removal, string $what): void
    {
        try {
            $removal();
        } catch (\Throwable $e) {
            $this->logger->warning('A file of deleted data could not be removed: {what}.', ['what' => $what, 'exception' => $e]);
        }
    }
}

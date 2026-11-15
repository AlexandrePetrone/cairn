<?php

declare(strict_types=1);

namespace App\Message\Attachment;

/**
 * Removes from the disk the files of rows a command erased in bulk
 * (IssueEraser): dispatched with DispatchAfterCurrentBusStamp, it runs
 * once the transaction of that command committed and is dropped with it
 * when it fails, so a file never goes while its row stays. Handled by
 * RemoveFilesHandler, at once.
 */
final readonly class RemoveFiles
{
    /**
     * @param list<string> $attachments the uuids of attachment files
     * @param list<string> $archives    the names of import archives, waiting or put away
     * @param list<int>    $imports     the ids of imports, for what they extracted
     */
    public function __construct(
        public array $attachments,
        public array $archives = [],
        public array $imports = [],
    ) {
    }
}

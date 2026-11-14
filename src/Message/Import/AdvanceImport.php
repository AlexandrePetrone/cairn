<?php

declare(strict_types=1);

namespace App\Message\Import;

/**
 * One batch of an import's work, for the worker (`async`): it reads the
 * import, does a batch and sends the next one while there is work left.
 * It does nothing unless the import is still in progress with the same
 * token: a message of an earlier start, or delivered twice, is dropped.
 */
final readonly class AdvanceImport
{
    public function __construct(
        public int $importId,
        public string $token,
    ) {
    }
}

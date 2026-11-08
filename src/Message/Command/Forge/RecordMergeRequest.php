<?php

declare(strict_types=1);

namespace App\Message\Command\Forge;

use App\Forge\Event\MergeRequestPayload;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A merge request of a linked repository mentions one issue, as the forge
 * last described it. No actor. Handled by RecordMergeRequestHandler,
 * which writes or refreshes the reference, journals a change of state and
 * returns the reference; an unknown key, a key of another project or a
 * read-only project end quietly with null.
 */
final readonly class RecordMergeRequest
{
    public function __construct(
        public int $codeRepositoryId,
        #[Assert\NotBlank]
        public string $issueKey,
        public MergeRequestPayload $mergeRequest,
    ) {
    }
}

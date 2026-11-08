<?php

declare(strict_types=1);

namespace App\Message\Command\Forge;

use App\Forge\Event\CommitPayload;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A push on a linked repository carried commits mentioning one issue.
 * No actor: the forge acted. Handled by RecordCommitsHandler, which
 * writes the commits new to the issue, journals them as one entry and
 * returns them; an unknown key, a key of another project, a read-only
 * project or nothing new all end quietly with an empty list.
 */
final readonly class RecordCommits
{
    /**
     * @param list<CommitPayload> $commits
     */
    public function __construct(
        public int $codeRepositoryId,
        #[Assert\NotBlank]
        public string $issueKey,
        #[Assert\NotBlank]
        public string $branch,
        #[Assert\NotBlank]
        public string $branchUrl,
        #[Assert\Count(min: 1)]
        public array $commits,
    ) {
    }
}

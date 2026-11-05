<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Links an issue to another one with a link type of the configuration,
 * read from the issue: `blocks` makes it block the other one. The actor
 * needs `issue.link` and must see both; an issue of another project is
 * for internal users only. Handled by AddLinkHandler, which returns the
 * IssueLink.
 */
final readonly class AddLink
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        #[Assert\NotBlank]
        public string $typeId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $otherKey,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use App\Entity\Enum\Criticality;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates an issue in a tracker, on the initial place of its workflow.
 * Handled by CreateIssueHandler, which returns the Issue.
 */
final readonly class CreateIssue
{
    /**
     * @param array<string, mixed> $fields field id => value, for the fields of the tracker's workflow
     */
    public function __construct(
        public int $actorId,
        public int $projectId,
        public int $trackerId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 200)]
        public string $title,
        public string $description = '',
        public Criticality $criticality = Criticality::DEFAULT,
        public ?int $assigneeId = null,
        public bool $private = false,
        public array $fields = [],
    ) {
    }
}

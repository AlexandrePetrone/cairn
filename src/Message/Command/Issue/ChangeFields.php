<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes fields of the issue's workflow; the fields not listed keep their
 * value. Handled by ChangeFieldsHandler.
 */
final readonly class ChangeFields
{
    /**
     * @param array<string, mixed> $values field id => new value, null to empty it
     */
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        public array $values,
    ) {
    }
}

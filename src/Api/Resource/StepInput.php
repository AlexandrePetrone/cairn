<?php

declare(strict_types=1);

namespace App\Api\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What PUT /issues/{key}/step takes: MoveIssue without its actor nor its
 * issue.
 */
final class StepInput
{
    /** Id of a place of the workflow of the issue's tracker. */
    #[Assert\NotBlank(normalizer: 'trim')]
    public ?string $place = null;
}

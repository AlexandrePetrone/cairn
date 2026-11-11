<?php

declare(strict_types=1);

namespace App\Api\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What PUT /issues/{key}/tracker takes: ChangeTracker without its actor
 * nor its issue.
 */
final class TrackerInput
{
    /** Id of another tracker of the issue's project. */
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $tracker = null;

    /** Id of a place of the workflow of that tracker: where the issue starts in it. */
    #[Assert\NotBlank(normalizer: 'trim')]
    public ?string $place = null;
}

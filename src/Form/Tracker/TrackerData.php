<?php

declare(strict_types=1);

namespace App\Form\Tracker;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the tracker forms collect: the workflow at creation only, since it
 * never changes, then the name, the description for clients, whether
 * external users see it and whether it takes new issues. The controller
 * turns it into CreateTracker or UpdateTracker.
 */
final class TrackerData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    public ?string $description = null;

    #[Assert\NotBlank(groups: ['creation'])]
    public ?string $workflowId = null;

    public bool $private = false;

    public bool $active = true;
}

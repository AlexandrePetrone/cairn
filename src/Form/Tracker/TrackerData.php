<?php

declare(strict_types=1);

namespace App\Form\Tracker;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the tracker forms collect: the workflow at creation only, since it
 * never changes, then the name, the description for clients, and whether
 * external users see it. The controller turns it into CreateTracker or
 * UpdateTracker; archiving is a button of the page, not a field.
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
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enum\Criticality;
use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the new issue form collects; the controller turns it into a
 * CreateIssue command. The constraints give the form its errors, the
 * command keeps its own as the guard of the bus.
 */
final class IssueData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 200)]
    public ?string $title = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    public ?string $description = null;

    public Criticality $criticality = Criticality::DEFAULT;

    public ?User $assignee = null;

    public bool $private = false;

    /** @var array<string, mixed> field id => value, for the fields of the tracker's workflow */
    public array $fields = [];
}

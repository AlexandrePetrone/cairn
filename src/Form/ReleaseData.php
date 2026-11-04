<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the release form collects: a name unique in the project, a planned
 * date and a description for clients. The controller turns it into
 * CreateRelease or UpdateRelease.
 */
final class ReleaseData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 40)]
    public ?string $name = null;

    public ?\DateTimeImmutable $plannedOn = null;

    public ?string $description = null;
}

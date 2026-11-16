<?php

declare(strict_types=1);

namespace App\Form\Template;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the form of a project template collects: its name and what it is
 * meant for. The controller turns it into CreateTemplate or
 * UpdateTemplate; its trackers and its rules have their own pages.
 */
final class TemplateData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    public ?string $description = null;
}

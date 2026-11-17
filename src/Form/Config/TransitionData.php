<?php

declare(strict_types=1);

namespace App\Form\Config;

use App\Config\TransitionStyle;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the dialog of an action of a workflow edits.
 */
final class TransitionData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $label = null;

    /** @var list<string> */
    #[Assert\Count(min: 1, minMessage: 'admin.configuration.action.from_required')]
    public array $from = [];

    #[Assert\NotBlank]
    public ?string $to = null;

    /** @var list<string> */
    #[Assert\Count(min: 1, minMessage: 'admin.configuration.action.roles_required')]
    public array $roles = [];

    public TransitionStyle $style = TransitionStyle::Default;

    public bool $requiresComment = false;

    #[Assert\Length(max: 255)]
    public ?string $commentPrompt = null;

    public ?string $description = null;
}

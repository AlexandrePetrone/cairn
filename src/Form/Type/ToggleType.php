<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;

/**
 * A checkbox drawn as a switch. Rendered by the `toggle_row` and
 * `toggle_widget` blocks of the form theme, which call Form:Toggle.
 *
 * @extends AbstractType<bool>
 */
final class ToggleType extends AbstractType
{
    public function getParent(): string
    {
        return CheckboxType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'toggle';
    }
}

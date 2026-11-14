<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

/**
 * One choice among many (the projects of the instance): a field that
 * opens a list narrowed by a search, instead of a select to scroll. A
 * ChoiceType in every other respect, groups included. Rendered by the
 * `picker_widget` block of the form theme, which calls Ui:Picker; the
 * `picker` controller writes the choice into the hidden field.
 *
 * @extends AbstractType<mixed>
 */
final class PickerType extends AbstractType
{
    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'picker';
    }
}

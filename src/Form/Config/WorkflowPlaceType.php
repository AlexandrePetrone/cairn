<?php

declare(strict_types=1);

namespace App\Form\Config;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A step to choose among `places` (label => id): the one to add to a
 * workflow, or the one that takes the issues of a step that leaves it
 * (`label`, `help`; not `required` when no issue stands there).
 *
 * @extends AbstractType<WorkflowPlaceData>
 */
final class WorkflowPlaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('place', ChoiceType::class, [
            'label' => $options['label_key'],
            'help' => $options['help_key'],
            'choices' => $options['places'],
            'choice_translation_domain' => false,
            'required' => $options['place_required'],
            'placeholder' => $options['place_required'] ? false : 'admin.configuration.workflow.form.no_target',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => WorkflowPlaceData::class, 'places' => [], 'label_key' => 'admin.configuration.workflow.form.place', 'help_key' => null, 'place_required' => true]);
    }
}

<?php

declare(strict_types=1);

namespace App\Form\Config;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The steps to add to a workflow, ticked among `places` (label => id):
 * those of the instance it does not use yet.
 *
 * @extends AbstractType<AddPlacesData>
 */
final class AddPlacesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('places', ChoiceType::class, [
            'label' => 'admin.configuration.workflow.form.places',
            'help' => 'admin.configuration.workflow.form.places_help',
            'choices' => $options['places'],
            'choice_translation_domain' => false,
            'multiple' => true,
            'expanded' => true,
            // Short labels, many of them: in a row.
            'attr' => ['class' => 'flex-row flex-wrap gap-x-5'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AddPlacesData::class, 'places' => []]);
    }
}

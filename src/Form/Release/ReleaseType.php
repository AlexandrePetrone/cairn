<?php

declare(strict_types=1);

namespace App\Form\Release;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A release of a project: its name, when it is planned, what it brings.
 *
 * @extends AbstractType<ReleaseData>
 */
final class ReleaseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'release.form.name',
                'help' => 'release.form.name_help',
            ])
            ->add('plannedOn', DateType::class, [
                'label' => 'release.form.planned_on',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'release.form.description',
                'help' => 'release.form.description_help',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReleaseData::class]);
    }
}

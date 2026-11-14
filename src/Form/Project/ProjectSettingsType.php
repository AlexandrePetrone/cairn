<?php

declare(strict_types=1);

namespace App\Form\Project;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The general settings of a project, for its manager: its name and the
 * description shown to clients. The key and the workspace never change.
 *
 * @extends AbstractType<ProjectSettingsData>
 */
final class ProjectSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'project.settings.name',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'project.settings.description',
                'help' => 'project.settings.description_help',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProjectSettingsData::class]);
    }
}

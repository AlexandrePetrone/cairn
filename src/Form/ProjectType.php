<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Workspace;
use App\Form\Type\KeyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A new project: its workspace among the ones given (the controller leaves
 * out the archived ones), its name and its key.
 *
 * @extends AbstractType<ProjectData> */
final class ProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('workspace', ChoiceType::class, [
                'choices' => $options['workspaces'],
                'choice_label' => static fn (Workspace $workspace): string => $workspace->getName(),
                'choice_value' => static fn (?Workspace $workspace): ?string => $workspace?->getKey(),
                'choice_translation_domain' => false,
                'placeholder' => 'form.choose',
                'label' => 'admin.project.workspace',
            ])
            ->add('name', TextType::class, [
                'label' => 'admin.project.name',
                'attr' => ['autofocus' => true],
            ])
            ->add('key', KeyType::class, [
                'label' => 'admin.project.key',
                'help' => 'admin.project.key_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => ProjectData::class])
            ->setRequired('workspaces')
            ->setAllowedTypes('workspaces', Workspace::class.'[]');
    }
}

<?php

declare(strict_types=1);

namespace App\Form\Forge;

use App\Entity\Enum\Forge;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Links a repository of a forge to the project.
 *
 * @extends AbstractType<CodeRepositoryData>
 */
final class CodeRepositoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('forge', EnumType::class, [
                'class' => Forge::class,
                'choices' => Forge::available(),
                'choice_label' => static fn (Forge $forge): string => $forge->label(),
                'label' => 'project.settings.repository.forge',
            ])
            ->add('path', TextType::class, [
                'label' => 'project.settings.repository.path',
                'help' => 'project.settings.repository.path_help',
                'attr' => ['placeholder' => 'project.settings.repository.path_placeholder', 'spellcheck' => 'false'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CodeRepositoryData::class]);
    }
}

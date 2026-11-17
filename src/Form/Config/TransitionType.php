<?php

declare(strict_types=1);

namespace App\Form\Config;

use App\Config\TransitionStyle;
use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The dialog of an action of a workflow: the button, where it leads from
 * and to among the `places` of the workflow, the `roles` that trigger it
 * (both label => id).
 *
 * @extends AbstractType<TransitionData>
 */
final class TransitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'admin.configuration.action.form.label'])
            ->add('from', ChoiceType::class, [
                'label' => 'admin.configuration.action.form.from',
                'choices' => $options['places'],
                'choice_translation_domain' => false,
                'multiple' => true,
                'expanded' => true,
                'attr' => ['class' => 'flex-row flex-wrap gap-x-5'],
            ])
            ->add('to', ChoiceType::class, [
                'label' => 'admin.configuration.action.form.to',
                'choices' => $options['places'],
                'choice_translation_domain' => false,
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'admin.configuration.action.form.roles',
                'help' => 'admin.configuration.action.form.roles_help',
                'choices' => $options['roles'],
                'choice_translation_domain' => false,
                'multiple' => true,
                'expanded' => true,
                'attr' => ['class' => 'flex-row flex-wrap gap-x-5'],
            ])
            ->add('style', EnumType::class, [
                'class' => TransitionStyle::class,
                'label' => 'admin.configuration.action.form.style',
                'choice_label' => static fn (TransitionStyle $style): string => 'admin.configuration.action.style.'.$style->value,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'admin.configuration.action.form.description',
                'help' => 'admin.configuration.action.form.description_help',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('requiresComment', ToggleType::class, [
                'label' => 'admin.configuration.action.form.requires_comment',
                'help' => 'admin.configuration.action.form.requires_comment_help',
                'required' => false,
            ])
            ->add('commentPrompt', TextType::class, [
                'label' => 'admin.configuration.action.form.comment_prompt',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TransitionData::class, 'places' => [], 'roles' => []]);
    }
}

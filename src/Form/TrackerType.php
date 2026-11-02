<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A tracker of a project. Creating one picks its workflow among those of
 * the configuration (`workflows`, labels as content); afterwards it can be
 * deactivated instead, never deleted.
 *
 * @extends AbstractType<TrackerData>
 */
final class TrackerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'project.settings.tracker.name',
                'attr' => ['autofocus' => $options['creation']],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'project.settings.tracker.description',
                'help' => 'project.settings.tracker.description_help',
                'required' => false,
                'attr' => ['rows' => 2],
            ]);
        if ($options['creation']) {
            $builder->add('workflowId', ChoiceType::class, [
                'label' => 'project.settings.tracker.workflow',
                'help' => 'project.settings.tracker.workflow_help',
                'choices' => array_flip($options['workflows']),
                'choice_translation_domain' => false,
                'placeholder' => 'form.choose',
            ]);
        }
        $builder->add('private', ToggleType::class, [
            'label' => 'project.settings.tracker.private',
            'help' => 'project.settings.tracker.private_help',
            'required' => false,
        ]);
        if (!$options['creation']) {
            $builder->add('active', ToggleType::class, [
                'label' => 'project.settings.tracker.active',
                'help' => 'project.settings.tracker.active_help',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => TrackerData::class,
                'creation' => false,
                'workflows' => [],
                'validation_groups' => static fn (FormInterface $form): array => $form->getConfig()->getOption('creation') ? ['Default', 'creation'] : ['Default'],
            ])
            ->setAllowedTypes('creation', 'bool')
            ->setAllowedTypes('workflows', 'string[]');
    }
}

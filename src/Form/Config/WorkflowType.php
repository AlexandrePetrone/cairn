<?php

declare(strict_types=1);

namespace App\Form\Config;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The dialog of a workflow: its name and the step new issues start on,
 * among `places` (label => id): every step of the instance for a new
 * workflow, its own afterwards.
 *
 * @extends AbstractType<WorkflowData>
 */
final class WorkflowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'admin.configuration.form.label'])
            ->add('initial', ChoiceType::class, [
                'label' => 'admin.configuration.workflow.form.initial',
                'help' => 'admin.configuration.workflow.form.initial_help',
                'choices' => $options['places'],
                'choice_translation_domain' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => WorkflowData::class, 'places' => []]);
    }
}

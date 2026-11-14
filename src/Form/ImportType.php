<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
use App\Form\Type\PickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A new import: a zip to send, or one of the archives waiting on the
 * server (`archives`: file name => label), and the target among the
 * given projects.
 *
 * @extends AbstractType<ImportData>
 */
final class ImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('upload', FileType::class, [
                'required' => false,
                'label' => 'admin.import.upload',
                'help' => 'admin.import.upload_help',
                'attr' => ['accept' => '.zip,application/zip'],
            ])
            ->add('archive', ChoiceType::class, [
                'choices' => array_flip($options['archives']),
                'choice_translation_domain' => false,
                'placeholder' => 'form.choose',
                'required' => false,
                'label' => 'admin.import.waiting',
                'help' => 'admin.import.waiting_help',
            ])
            ->add('project', PickerType::class, [
                'choices' => $options['projects'],
                'choice_label' => static fn (Project $project): string => $project->getIssuePrefix().' · '.$project->getName(),
                'choice_value' => static fn (?Project $project): ?string => null === $project ? null : (string) $project->getId(),
                'group_by' => static fn (Project $project): string => $project->getWorkspace()->getName(),
                'choice_translation_domain' => false,
                'placeholder' => 'form.choose',
                'label' => 'admin.import.project',
                'help' => 'admin.import.project_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => ImportData::class])
            ->setRequired(['archives', 'projects'])
            ->setAllowedTypes('archives', 'array')
            ->setAllowedTypes('projects', Project::class.'[]');
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Type\KeyType;
use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Creating a workspace asks for its key, which never changes afterwards;
 * its settings change the name and archive it.
 *
 * @extends AbstractType<WorkspaceData>
 */
final class WorkspaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'admin.workspace.name',
            'attr' => ['autofocus' => $options['creation']],
        ]);
        if ($options['creation']) {
            $builder->add('key', KeyType::class, [
                'label' => 'admin.workspace.key',
                'help' => 'admin.workspace.key_help',
            ]);
        } else {
            $builder->add('archived', ToggleType::class, [
                'label' => 'admin.workspace.archived_label',
                'help' => 'admin.workspace.archive_help',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => WorkspaceData::class,
                'creation' => false,
                'validation_groups' => static fn (FormInterface $form): array => $form->getConfig()->getOption('creation') ? ['Default', 'creation'] : ['Default'],
            ])
            ->setAllowedTypes('creation', 'bool');
    }
}

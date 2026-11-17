<?php

declare(strict_types=1);

namespace App\Form\Config;

use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The dialog of a role: its name, who may hold it, whether its holders
 * follow every new issue.
 *
 * @extends AbstractType<RoleData>
 */
final class RoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'admin.configuration.form.label'])
            ->add('internalOnly', ToggleType::class, [
                'label' => 'admin.configuration.roles.form.internal_only',
                'help' => 'admin.configuration.roles.form.internal_only_help',
                'required' => false,
            ])
            ->add('followsNewIssues', ToggleType::class, [
                'label' => 'admin.configuration.roles.form.follows_new_issues',
                'help' => 'admin.configuration.roles.form.follows_new_issues_help',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RoleData::class]);
    }
}

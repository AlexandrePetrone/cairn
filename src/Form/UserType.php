<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * An account: identity, type and password. A new account needs its first
 * password; an existing one may be deactivated, and keeps its password
 * when the field is left empty.
 *
 * @extends AbstractType<UserData>
 */
final class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $creation = $options['creation'];

        $builder
            ->add('firstName', TextType::class, ['label' => 'admin.user.first_name'])
            ->add('lastName', TextType::class, ['label' => 'admin.user.last_name'])
            ->add('email', EmailType::class, ['label' => 'admin.user.email'])
            ->add('type', ChoiceType::class, [
                'choices' => [
                    'admin.user.type.external' => UserData::EXTERNAL,
                    'admin.user.type.internal' => UserData::INTERNAL,
                    'admin.user.type.admin' => UserData::ADMIN,
                ],
                'expanded' => true,
                'label' => 'admin.user.type.label',
                'help' => 'admin.user.type.help',
            ])
            ->add('password', PasswordType::class, [
                'label' => $creation ? 'admin.user.password' : 'admin.user.new_password',
                'help' => $creation ? 'admin.user.password_help' : 'admin.user.new_password_help',
                'required' => $creation,
                'attr' => ['autocomplete' => 'new-password'],
            ]);
        if (!$creation) {
            $builder->add('active', ToggleType::class, [
                'label' => 'admin.user.active_label',
                'help' => 'admin.user.active_help',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => UserData::class,
                'creation' => false,
                'validation_groups' => static fn (FormInterface $form): array => $form->getConfig()->getOption('creation') ? ['Default', 'creation'] : ['Default'],
            ])
            ->setAllowedTypes('creation', 'bool');
    }
}

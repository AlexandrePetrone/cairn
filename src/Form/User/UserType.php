<?php

declare(strict_types=1);

namespace App\Form\User;

use App\Entity\Enum\AuthMethod;
use App\Form\Type\CompanyType;
use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * An account: identity, company and type. An existing one may be
 * deactivated. Nobody types a password here: a new account is invited
 * by mail. `sso` is the name of the identity provider when the account
 * may be told to sign in through it (Security/SingleSignOn), and adds
 * that choice.
 *
 * @extends AbstractType<UserData>
 */
final class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'admin.user.first_name'])
            ->add('lastName', TextType::class, ['label' => 'admin.user.last_name'])
            ->add('email', EmailType::class, ['label' => 'admin.user.email'])
            ->add('company', CompanyType::class, [
                'label' => 'admin.user.company',
                'help' => 'admin.user.company_help',
                'required' => false,
            ])
            ->add('type', ChoiceType::class, [
                'choices' => [
                    'admin.user.type.external' => UserData::EXTERNAL,
                    'admin.user.type.internal' => UserData::INTERNAL,
                    'admin.user.type.admin' => UserData::ADMIN,
                ],
                'expanded' => true,
                'label' => 'admin.user.type.label',
                'help' => 'admin.user.type.help',
            ]);
        if (null !== $options['sso']) {
            $builder->add('authMethod', EnumType::class, [
                'class' => AuthMethod::class,
                'choice_label' => static fn (AuthMethod $method): string => 'admin.user.auth_method.'.$method->value,
                'choice_translation_parameters' => static fn (): array => ['%name%' => $options['sso']],
                'expanded' => true,
                'label' => 'admin.user.auth_method.label',
                'help' => $options['creation'] ? 'admin.user.auth_method.help_new' : 'admin.user.auth_method.help',
                'help_translation_parameters' => ['%name%' => $options['sso']],
            ]);
        }

        if (!$options['creation']) {
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
                'sso' => null,
            ])
            ->setAllowedTypes('creation', 'bool')
            ->setAllowedTypes('sso', ['null', 'string']);
    }
}

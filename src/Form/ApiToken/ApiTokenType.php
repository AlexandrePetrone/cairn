<?php

declare(strict_types=1);

namespace App\Form\ApiToken;

use App\Entity\Enum\ApiTokenScope;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A new API token: its name, its scope and how long it works.
 *
 * @extends AbstractType<ApiTokenData>
 */
final class ApiTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'api_token.form.name',
                'help' => 'api_token.form.name_help',
            ])
            ->add('scope', EnumType::class, [
                'class' => ApiTokenScope::class,
                'choice_label' => static fn (ApiTokenScope $scope): string => 'api_token.scope.'.$scope->value,
                'label' => 'api_token.form.scope',
                'help' => 'api_token.form.scope_help',
                'expanded' => true,
            ])
            ->add('lifetime', ChoiceType::class, [
                'choices' => array_combine(array_map(static fn (string $lifetime): string => 'api_token.lifetime.'.$lifetime, ApiTokenData::lifetimes()), ApiTokenData::lifetimes()),
                'label' => 'api_token.form.lifetime',
                'help' => 'api_token.form.lifetime_help',
                'expanded' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ApiTokenData::class]);
    }
}

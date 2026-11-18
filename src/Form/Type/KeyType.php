<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The key of a workspace or a project: a few letters or digits, uppercase whatever
 * is typed. Rendered as a text field.
 *
 * @extends AbstractType<string> */
final class KeyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?string $key): ?string => $key,
            static fn (?string $key): ?string => null === $key ? null : mb_strtoupper(trim($key)),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['attr' => ['maxlength' => 5, 'class' => 'font-mono uppercase']]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}

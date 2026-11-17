<?php

declare(strict_types=1);

namespace App\Form\Config;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The dialog of a type of link: how it reads from each of the two issues.
 *
 * @extends AbstractType<LinkTypeData>
 */
final class LinkTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'admin.configuration.form.label'])
            ->add('inverseLabel', TextType::class, [
                'label' => 'admin.configuration.links.form.inverse',
                'help' => 'admin.configuration.links.form.inverse_help',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LinkTypeData::class]);
    }
}

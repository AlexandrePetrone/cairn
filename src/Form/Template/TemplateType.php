<?php

declare(strict_types=1);

namespace App\Form\Template;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A project template: its name and its description.
 *
 * @extends AbstractType<TemplateData>
 */
final class TemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'admin.template.name',
                'attr' => ['autofocus' => true],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'admin.template.description',
                'help' => 'admin.template.description_help',
                'required' => false,
                'attr' => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TemplateData::class]);
    }
}

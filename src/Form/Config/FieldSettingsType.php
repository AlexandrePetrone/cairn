<?php

declare(strict_types=1);

namespace App\Form\Config;

use App\Config\FieldType;
use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The dialog of a field. Its type is chosen when it is created (`create`)
 * and kept: the values issues hold of it are of that type.
 *
 * @extends AbstractType<FieldData>
 */
final class FieldSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, ['label' => 'admin.configuration.form.label']);
        if ($options['create']) {
            $builder->add('type', EnumType::class, [
                'class' => FieldType::class,
                'label' => 'admin.configuration.fields.type',
                'help' => 'admin.configuration.fields.form.type_help',
                'choice_label' => static fn (FieldType $type): string => 'field_type.'.$type->value,
            ]);
        }
        $builder
            ->add('choices', TextareaType::class, [
                'label' => 'admin.configuration.fields.form.choices',
                'help' => 'admin.configuration.fields.form.choices_help',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('placeholder', TextType::class, ['label' => 'admin.configuration.fields.form.placeholder', 'required' => false])
            ->add('help', TextType::class, ['label' => 'admin.configuration.fields.form.help', 'required' => false])
            ->add('internalOnly', ToggleType::class, [
                'label' => 'admin.configuration.fields.form.internal_only',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FieldData::class, 'create' => false]);
    }
}

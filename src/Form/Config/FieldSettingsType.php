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
 * and kept: the values issues hold of it are of that type. The choices of
 * a type that has some are typed with it, one label per line, shown as
 * the type is chosen; afterwards they have a page of their own.
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
                // The `reveal` controller, on the form, shows the choices for the types that have some.
                'attr' => ['data-reveal-target' => 'source', 'data-action' => 'reveal#update'],
            ]);
            // The choices of a field that offers some, one label per line; changed afterwards on a page of their own.
            $builder->add('choices', TextareaType::class, [
                'label' => 'admin.configuration.fields.form.choices',
                'help' => 'admin.configuration.fields.form.choices_help',
                'required' => false,
                'attr' => ['rows' => 4],
                'row_attr' => ['data-reveal-target' => 'panel', 'data-reveal-values' => implode(' ', array_map(static fn (FieldType $type): string => $type->value, array_filter(FieldType::cases(), static fn (FieldType $type): bool => $type->hasOptions())))],
            ]);
        }
        $builder
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

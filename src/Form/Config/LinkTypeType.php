<?php

declare(strict_types=1);

namespace App\Form\Config;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The dialog of a type of link: how it reads from each of the two issues.
 * Creating one (`create`) chooses whether it reads the same from both or
 * has a direction; afterwards only its labels change, the second one
 * being asked of a type that has a direction (`directed`).
 *
 * @extends AbstractType<LinkTypeData>
 */
final class LinkTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, ['label' => 'admin.configuration.form.label']);
        if ($options['create']) {
            $builder->add('nature', ChoiceType::class, [
                'label' => 'admin.configuration.links.form.nature',
                'help' => 'admin.configuration.links.form.nature_help',
                'choices' => ['admin.configuration.links.form.same' => LinkTypeData::SAME, 'admin.configuration.links.form.directed' => LinkTypeData::DIRECTED],
                // The `reveal` controller, on the form, asks for the other label only of a link that has a direction.
                'attr' => ['data-reveal-target' => 'source', 'data-action' => 'reveal#update'],
            ]);
        }
        if ($options['create'] || $options['directed']) {
            $builder->add('inverseLabel', TextType::class, [
                'label' => 'admin.configuration.links.form.inverse',
                'help' => 'admin.configuration.links.form.inverse_help',
                'row_attr' => $options['create'] ? ['data-reveal-target' => 'panel', 'data-reveal-values' => LinkTypeData::DIRECTED] : [],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LinkTypeData::class, 'create' => false, 'directed' => false]);
        $resolver->setAllowedTypes('create', 'bool');
        $resolver->setAllowedTypes('directed', 'bool');
    }
}

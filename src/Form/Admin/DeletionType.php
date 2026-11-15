<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Confirms a deletion that cannot be undone (a project, a workspace):
 * the key of what goes (`expected`) is typed again, so that the button is
 * never pressed for the wrong one.
 *
 * @extends AbstractType<DeletionData>
 */
final class DeletionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('confirmation', TextType::class, [
            'label' => 'admin.delete.confirmation',
            'label_translation_parameters' => ['%key%' => $options['expected']],
            'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'characters', 'spellcheck' => 'false'],
            'constraints' => [new Assert\IdenticalTo(value: $options['expected'], message: 'admin.delete_mismatch')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => DeletionData::class])
            ->setRequired('expected')
            ->setAllowedTypes('expected', 'string');
    }
}

<?php

declare(strict_types=1);

namespace App\Form\Tag;

use App\Entity\Enum\TagColour;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A tag of a project: its name and its colour, one of the palette, which
 * the dialog draws as the tags themselves.
 *
 * @extends AbstractType<TagData>
 */
final class TagType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'tag.form.name',
                'help' => 'tag.form.name_help',
            ])
            ->add('colour', EnumType::class, [
                'class' => TagColour::class,
                'label' => 'tag.form.colour',
                'choice_label' => static fn (TagColour $colour): string => 'tag.colour.'.$colour->value,
                'expanded' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TagData::class]);
    }
}

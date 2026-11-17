<?php

declare(strict_types=1);

namespace App\Form\Config;

use App\Entity\Enum\PlaceStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The dialog of a step of the instance: its name and its status, the
 * family that orders the lists and says when an issue is finished.
 *
 * @extends AbstractType<PlaceData>
 */
final class PlaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'admin.configuration.form.label'])
            ->add('status', EnumType::class, [
                'class' => PlaceStatus::class,
                'label' => 'admin.configuration.places.form.status',
                'help' => 'admin.configuration.places.form.status_help',
                'choice_label' => static fn (PlaceStatus $status): string => 'status.'.$status->value,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PlaceData::class]);
    }
}

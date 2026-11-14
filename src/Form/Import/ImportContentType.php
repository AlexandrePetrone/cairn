<?php

declare(strict_types=1);

namespace App\Form\Import;

use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * What an import takes of its archive: the closed issues or not, the
 * files, the versions.
 *
 * @extends AbstractType<ImportMappingData>
 */
final class ImportContentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['closed', 'files', 'versions'] as $choice) {
            $builder->add($choice, ToggleType::class, [
                'required' => false,
                'label' => 'admin.import.content.'.$choice,
                'help' => 'admin.import.content.'.$choice.'_help',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ImportMappingData::class]);
    }
}

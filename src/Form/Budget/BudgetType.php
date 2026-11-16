<?php

declare(strict_types=1);

namespace App\Form\Budget;

use App\Entity\Enum\BudgetUnit;
use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A budget of a project: its name and its volume, typed in the `unit` of
 * the project; `closable`, for a budget that exists, adds closing it.
 *
 * @extends AbstractType<BudgetData>
 */
final class BudgetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'budget.form.name',
                'help' => 'budget.form.name_help',
            ])
            ->add('volume', NumberType::class, [
                'label' => 'budget.form.volume.'.$options['unit']->value,
                'help' => 'budget.form.volume_help',
                'html5' => true,
                'attr' => ['min' => 0, 'step' => 'any'],
            ]);
        if ($options['closable']) {
            $builder->add('closed', ToggleType::class, [
                'label' => 'budget.form.closed',
                'help' => 'budget.form.closed_help',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BudgetData::class, 'closable' => false]);
        $resolver->setRequired('unit');
        $resolver->setAllowedTypes('unit', BudgetUnit::class);
        $resolver->setAllowedTypes('closable', 'bool');
    }
}

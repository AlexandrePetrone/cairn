<?php

declare(strict_types=1);

namespace App\Form\Budget;

use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Enum\BudgetUnit;
use App\Entity\Issue;
use App\Form\Type\PickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A line of a budget: its status, its quantity typed in the `unit` of the
 * project, a label and, when the line is written, the issue it is for,
 * picked among `issues` (the issue of a line never changes: the form of
 * an existing line is given none).
 *
 * @extends AbstractType<BudgetLineData>
 */
final class BudgetLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (null !== $options['issues']) {
            $builder->add('issue', PickerType::class, [
                'choices' => $options['issues'],
                'choice_label' => static fn (Issue $issue): string => $issue->getKey().' · '.$issue->getTitle(),
                'choice_value' => static fn (?Issue $issue): ?string => null === $issue ? null : (string) $issue->getId(),
                'choice_translation_domain' => false,
                'placeholder' => 'budget.line.form.no_issue',
                'required' => false,
                'label' => 'budget.line.form.issue',
                'help' => 'budget.line.form.issue_help',
            ]);
        }
        $builder
            ->add('label', TextType::class, [
                'label' => 'budget.line.form.label',
                'help' => 'budget.line.form.label_help',
                'required' => false,
            ])
            ->add('status', EnumType::class, [
                'class' => BudgetLineStatus::class,
                'label' => 'budget.line.form.status',
                'choice_label' => static fn (BudgetLineStatus $status): string => 'budget.status.'.$status->value,
            ])
            ->add('quantity', NumberType::class, [
                'label' => 'budget.line.form.quantity.'.$options['unit']->value,
                'help' => 'budget.line.form.quantity_help',
                'html5' => true,
                'required' => false,
                // opened to give the line its quantity, the dialog starts in that field
                'attr' => ['step' => 'any'] + ($options['asks_quantity'] ? ['data-controller' => 'autofocus'] : []),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BudgetLineData::class, 'issues' => null, 'asks_quantity' => false]);
        $resolver->setRequired('unit');
        $resolver->setAllowedTypes('unit', BudgetUnit::class);
        $resolver->setAllowedTypes('asks_quantity', 'bool');
        $resolver->setAllowedTypes('issues', ['null', Issue::class.'[]']);
    }
}

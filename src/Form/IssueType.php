<?php

declare(strict_types=1);

namespace App\Form;

use App\Config\Definition\FieldDefinition;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\FieldType;
use App\Entity\Enum\Criticality;
use App\Entity\User;
use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The new issue form. The fields of the tracker's workflow are built from
 * the configuration in force; what the viewer may qualify (criticality,
 * assignee, privacy) is decided by the controller through the options.
 *
 * @extends AbstractType<IssueData>
 */
final class IssueType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $workflow = $options['workflow'];
        $definition = $options['definition'];
        \assert($workflow instanceof WorkflowDefinition && $definition instanceof InstanceDefinition);

        $builder
            ->add('title', TextType::class, [
                'label' => 'issue.field.title',
                'attr' => ['placeholder' => 'issue.field.title_placeholder', 'autofocus' => true],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'issue.field.description',
                'attr' => ['placeholder' => 'issue.field.description_placeholder', 'rows' => 8],
            ]);

        $fields = $builder->create('fields', FormType::class, ['label' => false]);
        foreach ($workflow->fields as $usage) {
            $field = $definition->fields[$usage->field];
            if ($field->internalOnly && !$options['internal']) {
                continue;
            }
            [$type, $typeOptions] = $this->control($field);
            $fields->add($field->id, $type, $typeOptions + [
                // Configuration labels are content, never translated.
                'translation_domain' => false,
                'label' => $field->label,
                'help' => $field->help,
                'required' => $usage->required,
                'constraints' => $usage->required && FieldType::Bool !== $field->type ? [new NotBlank()] : [],
                'attr' => null === $field->placeholder ? [] : ['placeholder' => $field->placeholder],
            ]);
        }
        $builder->add($fields);

        if ($options['criticality']) {
            $builder->add('criticality', EnumType::class, [
                'class' => Criticality::class,
                'choices' => [Criticality::Blocking, Criticality::High, Criticality::Normal, Criticality::Low],
                'choice_label' => static fn (Criticality $criticality): string => 'criticality.'.$criticality->value,
                'expanded' => true,
                'label' => 'issue.field.criticality',
            ]);
        }
        if (null !== $options['members']) {
            $builder->add('assignee', ChoiceType::class, [
                'choices' => $options['members'],
                'choice_label' => static fn (User $user): string => $user->getFullName(),
                'choice_value' => static fn (?User $user): ?string => null === $user ? null : (string) $user->getId(),
                'choice_translation_domain' => false,
                'placeholder' => 'issue.field.assignee_none',
                'required' => false,
                'label' => 'issue.field.assignee',
            ]);
        }
        if ($options['private']) {
            $builder->add('private', ToggleType::class, [
                'label' => 'issue.field.private',
                'help' => 'issue.field.private_help',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => IssueData::class,
                'internal' => false,
                'criticality' => true,
                'members' => null,
                'private' => false,
            ])
            ->setRequired(['workflow', 'definition'])
            ->setAllowedTypes('workflow', WorkflowDefinition::class)
            ->setAllowedTypes('definition', InstanceDefinition::class)
            ->setAllowedTypes('internal', 'bool')
            ->setAllowedTypes('criticality', 'bool')
            ->setAllowedTypes('members', ['null', User::class.'[]'])
            ->setAllowedTypes('private', 'bool')
            ->setInfo('internal', 'whether internal-only fields are shown')
            ->setInfo('members', 'the people the issue may be assigned to, null when the viewer may not assign')
            ->setInfo('private', 'whether the viewer may make the issue private');
    }

    /**
     * The form type behind a configured field type.
     *
     * @return array{class-string<AbstractType<mixed>>, array<string, mixed>}
     */
    private function control(FieldDefinition $field): array
    {
        return match ($field->type) {
            FieldType::Text => [TextType::class, []],
            FieldType::Textarea, FieldType::RichText => [TextareaType::class, ['attr' => ['rows' => 3]]],
            FieldType::Select => [ChoiceType::class, [
                'choices' => array_flip($field->options),
                'choice_translation_domain' => false,
                'placeholder' => $this->translator->trans('form.choose'),
            ]],
            FieldType::Choice => [ChoiceType::class, [
                'choices' => array_flip($field->options),
                'choice_translation_domain' => false,
                'expanded' => true,
                // No "none" segment: leaving every segment off is the empty value.
                'placeholder' => false,
            ]],
            FieldType::Number => [NumberType::class, ['html5' => true]],
            FieldType::Date => [DateType::class, ['widget' => 'single_text', 'input' => 'string']],
            FieldType::Url => [UrlType::class, ['default_protocol' => null]],
            FieldType::Bool => [ToggleType::class, []],
        };
    }
}

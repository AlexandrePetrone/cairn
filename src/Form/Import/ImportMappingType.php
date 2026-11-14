<?php

declare(strict_types=1);

namespace App\Form\Import;

use App\Config\Definition\InstanceDefinition;
use App\Entity\Enum\Criticality;
use App\Entity\Tracker;
use App\Import\ImportManifest;
use App\Import\ImportMapping;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The correspondences of an import, built from what its archive
 * declares: where each tracker goes, the place of each status in use,
 * by workflow of Cairn, and what each field, priority and link type
 * becomes. The labels of the archive and of the configuration are
 * content: only our own choices ("not imported"…) are translated.
 *
 * @extends AbstractType<ImportMappingData>
 */
final class ImportMappingType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $manifest = $options['manifest'];
        $definition = $options['definition'];
        $mapping = $options['mapping'];
        \assert($manifest instanceof ImportManifest && $definition instanceof InstanceDefinition && $mapping instanceof ImportMapping);
        $skip = $this->translator->trans('admin.import.choice.skip');
        $choice = static fn (array $choices): array => ['choices' => $choices, 'choice_translation_domain' => false, 'label' => false, 'required' => false, 'placeholder' => false];

        $targets = [];
        foreach ($options['trackers'] as $tracker) {
            \assert($tracker instanceof Tracker);
            $targets[$this->translator->trans('admin.import.choice.tracker', ['%tracker%' => $tracker->getName()])] = 'tracker:'.$tracker->getId();
        }
        foreach ($definition->workflows as $workflow) {
            $targets[$this->translator->trans('admin.import.choice.new_tracker', ['%workflow%' => $workflow->label])] = 'new:'.$workflow->id;
        }
        $targets[$skip] = ImportMapping::SKIP;
        $trackers = $builder->create('trackers', FormType::class, ['label' => false]);
        foreach (array_keys($manifest->trackers) as $id) {
            $trackers->add($builder->create((string) $id, FormType::class, ['label' => false, 'constraints' => [new Callback(self::nameOfNewTracker(...))]])
                ->add('target', ChoiceType::class, $choice($targets))
                ->add('name', TextType::class, ['label' => false, 'required' => false, 'constraints' => [new Length(max: 120)]]));
        }
        $builder->add($trackers);

        $places = $builder->create('places', FormType::class, ['label' => false]);
        // The statuses in use and their places are the mapping's: the page gives the one it is drawing.
        foreach ($mapping->places as $workflowId => $sources) {
            $choices = [];
            foreach ($definition->workflow((string) $workflowId)->places as $place) {
                $choices[$place->label] = $place->id;
            }
            $workflow = $builder->create((string) $workflowId, FormType::class, ['label' => false]);
            foreach ($sources as $source => $statuses) {
                // An id of the archive made of digits is an integer key: form names are strings.
                $row = $builder->create((string) $source, FormType::class, ['label' => false]);
                foreach (array_keys($statuses) as $statusId) {
                    $row->add((string) $statusId, ChoiceType::class, $choice($choices));
                }
                $workflow->add($row);
            }
            $places->add($workflow);
        }
        $builder->add($places);

        $fields = $builder->create('fields', FormType::class, ['label' => false]);
        foreach ($manifest->fields as $id => $field) {
            $choices = [];
            foreach ($definition->fields as $candidate) {
                if (ImportMapping::fits($field['type'], $candidate->type)) {
                    $choices[$this->translator->trans('admin.import.choice.field', ['%field%' => $candidate->label])] = 'field:'.$candidate->id;
                }
            }
            $choices[$this->translator->trans('admin.import.choice.append')] = ImportMapping::APPEND;
            $choices[$skip] = ImportMapping::IGNORE;
            $fields->add((string) $id, ChoiceType::class, $choice($choices));
        }
        $builder->add($fields);

        $levels = [];
        foreach (Criticality::cases() as $criticality) {
            $levels[$this->translator->trans('criticality.'.$criticality->value)] = $criticality->value;
        }
        $priorities = $builder->create('priorities', FormType::class, ['label' => false]);
        foreach (array_keys($manifest->priorities) as $id) {
            $priorities->add((string) $id, ChoiceType::class, $choice($levels));
        }
        $builder->add($priorities);

        $linkChoices = [];
        foreach ($definition->links as $link) {
            $linkChoices[$link->label] = $link->id;
        }
        $links = $builder->create('links', FormType::class, ['label' => false]);
        foreach (array_keys($manifest->linkTypes) as $id) {
            $links->add((string) $id, ChoiceType::class, ['placeholder' => $skip] + $choice($linkChoices));
        }
        $builder->add($links);
    }

    /**
     * A tracker to create needs its name.
     *
     * @param array{target?: string|null, name?: string|null}|null $row
     */
    public static function nameOfNewTracker(?array $row, ExecutionContextInterface $context): void
    {
        if (str_starts_with((string) ($row['target'] ?? ''), 'new:') && '' === trim((string) ($row['name'] ?? ''))) {
            $context->buildViolation('admin.import.tracker_name_required')->atPath('[name]')->addViolation();
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => ImportMappingData::class])
            ->setRequired(['manifest', 'definition', 'mapping', 'trackers'])
            ->setAllowedTypes('manifest', ImportManifest::class)
            ->setAllowedTypes('definition', InstanceDefinition::class)
            ->setAllowedTypes('mapping', ImportMapping::class)
            ->setAllowedTypes('trackers', Tracker::class.'[]');
    }
}

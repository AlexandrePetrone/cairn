<?php

declare(strict_types=1);

namespace App\Form\Import;

use App\Config\Definition\InstanceDefinition;
use App\Import\ImportManifest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The accounts of an import, one row per person of its archive: for
 * someone without an account in Cairn (`accounts`: person id => the
 * account of the same email), whether theirs is created deactivated or
 * active; and for everyone but those who already hold a role on the
 * project (`settled`), their role there, among those their account may
 * hold: a new account is external.
 *
 * @extends AbstractType<ImportMappingData>
 */
final class ImportAccountsType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $manifest = $options['manifest'];
        $definition = $options['definition'];
        \assert($manifest instanceof ImportManifest && $definition instanceof InstanceDefinition);
        $states = [
            $this->translator->trans('admin.import.accounts.deactivated') => false,
            $this->translator->trans('admin.import.accounts.active') => true,
        ];

        $people = $builder->create('people', FormType::class, ['label' => false]);
        foreach (array_keys($manifest->people) as $id) {
            $id = (string) $id;
            $account = $options['accounts'][$id] ?? null;
            $row = $builder->create($id, FormType::class, ['label' => false]);
            if (null === $account) {
                $row->add('active', ChoiceType::class, ['choices' => $states, 'choice_translation_domain' => false, 'label' => false]);
            }
            if (!isset($options['settled'][$id])) {
                $roles = [];
                foreach ($definition->roles as $role) {
                    if (true === $account?->isInternal() || !$role->internalOnly) {
                        $roles[$role->label] = $role->id;
                    }
                }
                $row->add('role', ChoiceType::class, ['choices' => $roles, 'choice_translation_domain' => false, 'label' => false, 'required' => false, 'placeholder' => $this->translator->trans('admin.import.choice.no_role')]);
            }
            $people->add($row);
        }
        $builder->add($people);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => ImportMappingData::class, 'accounts' => [], 'settled' => []])
            ->setRequired(['manifest', 'definition'])
            ->setAllowedTypes('manifest', ImportManifest::class)
            ->setAllowedTypes('definition', InstanceDefinition::class)
            ->setAllowedTypes('accounts', 'array')
            ->setAllowedTypes('settled', 'array');
    }
}

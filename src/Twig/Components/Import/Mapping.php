<?php

declare(strict_types=1);

namespace App\Twig\Components\Import;

use App\Config\ConfigRegistry;
use App\Entity\Enum\ImportStatus;
use App\Entity\Import;
use App\Form\Import\ImportMappingData;
use App\Form\Import\ImportMappingType;
use App\Import\ImportAnalysis;
use App\Import\ImportManifest;
use App\Import\ImportMapping;
use App\Message\Command\Import\ConfigureImport;
use App\Message\CommandBus;
use App\Repository\TrackerRepository;
use App\Twig\Components\DispatchesCommands;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The correspondences of an import: where each tracker of the archive
 * goes, the place of each status, what each field, priority and link
 * type becomes. The statuses to place depend on the trackers chosen
 * above them, so the form is redrawn at each change: sending a tracker
 * to another workflow shows the statuses of that workflow at once, with
 * a guessed place each. Nothing is kept until `save`, which dispatches
 * ConfigureImport and leads to the accounts.
 */
#[AsLiveComponent('Import:Mapping')]
final class Mapping extends AbstractController
{
    use ComponentToolsTrait;
    use ComponentWithFormTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public Import $import;

    public function __construct(
        private readonly ConfigRegistry $config,
        private readonly TrackerRepository $trackers,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getManifest(): ImportManifest
    {
        return ImportManifest::fromArray($this->import->getManifest());
    }

    public function getAnalysis(): ImportAnalysis
    {
        return ImportAnalysis::fromArray($this->import->getAnalysis());
    }

    /** The label of a workflow of Cairn, for the title of its statuses. */
    public function workflowLabel(string $workflowId): string
    {
        return $this->config->definition()->workflow($workflowId)->label;
    }

    #[LiveAction]
    public function save(CommandBus $commands): ?Response
    {
        if (ImportStatus::Configuring !== $this->import->getStatus()) {
            return $this->redirectToRoute('app_admin_import', ['id' => $this->import->getId()]);
        }
        $this->submitForm();
        $data = $this->getForm()->getData();
        \assert($data instanceof ImportMappingData);
        $manifest = $this->getManifest();
        $trackers = [];
        foreach ($data->trackers as $id => $tracker) {
            $name = trim((string) ($tracker['name'] ?? ''));
            $trackers[$id] = ['target' => (string) $tracker['target'], 'name' => '' === $name ? $manifest->trackers[$id]['label'] : $name];
        }

        $saved = $this->tryCommand($commands, new ConfigureImport(
            (int) $this->import->getId(),
            trackers: $trackers,
            places: $data->places,
            fields: $data->fields,
            priorities: $data->priorities,
            links: $data->links,
        ));

        return $saved ? $this->redirectToRoute('app_admin_import_accounts', ['id' => $this->import->getId()]) : null;
    }

    /**
     * The form of the mapping as the page stands. The live endpoint
     * requires a custom header that keeps cross-origin requests out, so
     * the form carries no CSRF token of its own.
     *
     * @return FormInterface<ImportMappingData>
     */
    protected function instantiateForm(): FormInterface
    {
        $draft = $this->draft();
        if ([] !== $this->formValues) {
            // The places follow the trackers: those newly in use arrive with their guess, those out of use leave.
            $this->formValues['places'] = $draft->places;
        }

        return $this->forms->createNamed('import_mapping', ImportMappingType::class, ImportMappingData::of($draft), [
            'manifest' => $this->getManifest(),
            'definition' => $this->config->definition(),
            'mapping' => $draft,
            'trackers' => $this->trackers->findByProject($this->import->getProject(), includeInactive: true),
            'csrf_protection' => false,
        ]);
    }

    /**
     * The mapping kept on the import, with the trackers and places being
     * chosen on the page in place of its own, and a place for every
     * status those trackers bring in.
     */
    private function draft(): ImportMapping
    {
        $mapping = ImportMapping::fromArray($this->import->getMapping());
        if ([] === $this->formValues) {
            return $mapping;
        }

        $trackers = [];
        foreach (array_keys($this->getManifest()->trackers) as $id) {
            $chosen = $this->formValues['trackers'][$id] ?? [];
            $trackers[$id] = [
                'target' => \is_string($chosen['target'] ?? null) ? $chosen['target'] : ($mapping->trackers[$id]['target'] ?? ImportMapping::SKIP),
                'name' => \is_string($chosen['name'] ?? null) ? $chosen['name'] : ($mapping->trackers[$id]['name'] ?? ''),
            ];
        }
        $places = \is_array($this->formValues['places'] ?? null) ? $this->formValues['places'] : $mapping->places;

        return $mapping->with(['trackers' => $trackers, 'places' => $places])->withGuessedPlaces(
            $this->getManifest(),
            $this->getAnalysis(),
            $this->config->definition(),
            $this->trackers->findByProject($this->import->getProject(), includeInactive: true),
        );
    }
}

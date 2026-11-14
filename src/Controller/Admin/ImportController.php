<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\ConfigRegistry;
use App\Entity\Enum\ImportRecordKind;
use App\Entity\Enum\ImportStatus;
use App\Entity\Import;
use App\Entity\User;
use App\Form\Import\ImportAccountsType;
use App\Form\Import\ImportContentType;
use App\Form\Import\ImportData;
use App\Form\Import\ImportMappingData;
use App\Form\Import\ImportType;
use App\Import\ImportAnalysis;
use App\Import\ImportManifest;
use App\Import\ImportMapping;
use App\Import\ImportsDirectory;
use App\Message\Command\Import\AbandonImport;
use App\Message\Command\Import\ConfigureImport;
use App\Message\Command\Import\CreateImport;
use App\Message\Command\Import\StartImport;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjects;
use App\Message\QueryBus;
use App\MessageHandler\Command\Import\ImportException;
use App\Repository\ImportRecordRepository;
use App\Repository\ImportRepository;
use App\Repository\TrackerRepository;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use App\Security\Viewer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The imports of archives: their history, the description of the archive
 * format, a new import, and the pages of one import. Between its
 * analysis and its run an import is configured in steps, one route each
 * (content, mapping, accounts, summary); otherwise its page shows its
 * progress while a worker is on it, then its report.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/imports')]
final class ImportController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly ImportRepository $imports,
        private readonly ImportRecordRepository $records,
        private readonly TrackerRepository $trackers,
        private readonly UserRepository $users,
        private readonly ImportsDirectory $directory,
        private readonly ConfigRegistry $config,
        private readonly AccessControl $access,
        private readonly TranslatorInterface $translator,
        private readonly Viewer $viewer,
    ) {
    }

    #[Route('', name: 'app_admin_imports', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('admin/import/list.html.twig', ['imports' => $this->imports->findAllLatestFirst()]);
    }

    #[Route('/format', name: 'app_admin_import_format', methods: ['GET'])]
    public function format(): Response
    {
        $example = $this->getParameter('kernel.project_dir').'/templates/admin/import/example/';
        $issues = array_values(array_filter(explode("\n", (string) file_get_contents($example.'issues.ndjson'))));

        return $this->render('admin/import/format.html.twig', [
            'manifest' => trim((string) file_get_contents($example.'manifest.json')),
            'issues' => implode("\n", $issues),
            'issue' => json_encode(json_decode($issues[0], true), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        ]);
    }

    #[Route('/new', name: 'app_admin_import_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $inUse = $this->imports->filesInUse();
        $archives = [];
        foreach ($this->directory->waiting() as $name => $size) {
            if (!\in_array($name, $inUse, true)) {
                $archives[$name] = \sprintf('%s · %s', $name, $this->size($size));
            }
        }
        $data = new ImportData();
        $form = $this->createForm(ImportType::class, $data, [
            'archives' => $archives,
            'projects' => array_values(array_filter($this->queries->ask(new ListProjects()), static fn ($project): bool => !$project->isReadOnly())),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $file = null !== $data->upload ? $this->directory->receive($data->upload) : (string) $data->archive;
                $import = $this->commands->dispatch(new CreateImport((int) $this->viewer->get()->getId(), $file, (int) $data->project?->getId()));
                \assert($import instanceof Import);
                $this->commands->dispatch(new StartImport((int) $import->getId()));

                return $this->redirectToRoute('app_admin_import', ['id' => $import->getId()]);
            } catch (ImportException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('admin/import/new.html.twig', [
            'form' => $form,
            'uploadLimit' => $this->size((int) UploadedFile::getMaxFilesize()),
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}', name: 'app_admin_import', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Import $import): Response
    {
        if (ImportStatus::Configuring === $import->getStatus()) {
            return $this->redirectToRoute('app_admin_import_content', ['id' => $import->getId()]);
        }
        $created = array_values(array_filter($this->records->localIds($import, ImportRecordKind::User)));

        return $this->render('admin/import/show.html.twig', [
            'import' => $import,
            'counts' => $this->records->countDone($import),
            'refused' => $this->records->findRefused($import),
            'toInvite' => array_values(array_filter([] === $created ? [] : $this->users->findBy(['id' => $created], ['lastName' => 'ASC']), static fn (User $user): bool => $user->awaitsPassword())),
        ]);
    }

    #[Route('/{id}/content', name: 'app_admin_import_content', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function content(Import $import, Request $request): Response
    {
        if (null !== $redirect = $this->unlessConfiguring($import)) {
            return $redirect;
        }
        $data = ImportMappingData::of(ImportMapping::fromArray($import->getMapping()));
        $form = $this->createForm(ImportContentType::class, $data);

        return $this->step($import, $form, $request, 'content', 'app_admin_import_mapping', static fn () => new ConfigureImport((int) $import->getId(), closed: $data->closed, files: $data->files, versions: $data->versions));
    }

    #[Route('/{id}/mapping', name: 'app_admin_import_mapping', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function mapping(Import $import): Response
    {
        return $this->unlessConfiguring($import) ?? $this->render('admin/import/mapping.html.twig', ['import' => $import]);
    }

    #[Route('/{id}/accounts', name: 'app_admin_import_accounts', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function accounts(Import $import, Request $request): Response
    {
        if (null !== $redirect = $this->unlessConfiguring($import)) {
            return $redirect;
        }
        $manifest = ImportManifest::fromArray($import->getManifest());
        $mapping = ImportMapping::fromArray($import->getMapping());
        $definition = $this->config->definition();

        $byEmail = [];
        foreach ($this->users->findBy(['email' => array_column($manifest->people, 'email')]) as $user) {
            $byEmail[$user->getEmail()] = $user;
        }
        $accounts = [];
        $settled = [];
        $data = new ImportMappingData();
        foreach ($manifest->people as $id => $person) {
            $id = (string) $id;
            $account = $accounts[$id] = $byEmail[$person['email']] ?? null;
            $held = null === $account ? null : $this->access->roleOn($account, $import->getProject());
            if (null !== $held) {
                $settled[$id] = $definition->role($held)->label;
            }
            // Chosen before, or offered at first from the role the person had in the archive.
            $role = isset($mapping->people[$id]) ? (string) $mapping->roleOf($id) : ImportMapping::guessedRole($manifest->roles[$person['role'] ?? ''] ?? null, $definition, true === $account?->isInternal());
            $offered = '' !== $role && $definition->hasRole($role) && (true === $account?->isInternal() || !$definition->role($role)->internalOnly);
            $data->people[$id] = ['active' => $mapping->isActive($id), 'role' => $offered ? $role : null];
        }
        $form = $this->createForm(ImportAccountsType::class, $data, ['manifest' => $manifest, 'definition' => $definition, 'accounts' => $accounts, 'settled' => $settled]);

        return $this->step($import, $form, $request, 'accounts', 'app_admin_import_summary', static function () use ($import, $data): ConfigureImport {
            $people = [];
            foreach ($data->people as $id => $person) {
                $people[(string) $id] = ['active' => true === ($person['active'] ?? false), 'role' => (string) ($person['role'] ?? '')];
            }

            return new ConfigureImport((int) $import->getId(), people: $people);
        }, [
            'manifest' => $manifest,
            'analysis' => $analysis = ImportAnalysis::fromArray($import->getAnalysis()),
            'assigned' => $analysis->openAssigned($mapping, $this->trackers->findByProject($import->getProject(), includeInactive: true)),
            'accounts' => $accounts,
            'settled' => $settled,
        ]);
    }

    #[Route('/{id}/summary', name: 'app_admin_import_summary', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function summary(Import $import): Response
    {
        if (null !== $redirect = $this->unlessConfiguring($import)) {
            return $redirect;
        }
        $analysis = ImportAnalysis::fromArray($import->getAnalysis());
        $mapping = ImportMapping::fromArray($import->getMapping());
        $manifest = ImportManifest::fromArray($import->getManifest());
        $trackers = $this->trackers->findByProject($import->getProject(), includeInactive: true);

        // The open issues of the people left without a role arrive assigned to nobody.
        $unassigned = [];
        foreach ($analysis->openAssigned($mapping, $trackers) as $id => $issues) {
            $account = $this->users->findOneBy(['email' => $manifest->people[$id]['email']]);
            if (null === $mapping->roleOf((string) $id) && (null === $account || null === $this->access->roleOn($account, $import->getProject()))) {
                $unassigned[$manifest->nameOf((string) $id)] = $issues;
            }
        }

        return $this->render('admin/import/summary.html.twig', [
            'import' => $import,
            'unassigned' => $unassigned,
            'totals' => $analysis->totals($mapping, $trackers),
            'versions' => $mapping->versions ? \count($analysis->versions) : 0,
        ]);
    }

    #[Route('/{id}/start', name: 'app_admin_import_start', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function start(Import $import): Response
    {
        $configuring = ImportStatus::Configuring === $import->getStatus();
        try {
            $this->commands->dispatch(new StartImport((int) $import->getId()));
            $this->addFlash('success', $this->translator->trans($configuring ? 'admin.import.started' : 'admin.import.resumed'));
        } catch (ImportException $e) {
            $this->addFlash('error', $e->trans($this->translator));

            return $this->redirectToRoute($configuring ? 'app_admin_import_summary' : 'app_admin_import', ['id' => $import->getId()]);
        }

        return $this->redirectToRoute('app_admin_import', ['id' => $import->getId()]);
    }

    #[Route('/{id}/abandon', name: 'app_admin_import_abandon', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function abandon(Import $import): Response
    {
        try {
            $this->commands->dispatch(new AbandonImport((int) $import->getId()));
            $this->addFlash('success', $this->translator->trans('admin.import.abandoned'));
        } catch (ImportException $e) {
            $this->addFlash('error', $e->trans($this->translator));

            return $this->redirectToRoute('app_admin_import', ['id' => $import->getId()]);
        }

        return $this->redirectToRoute('app_admin_imports');
    }

    /**
     * A configuration step: its form saves its part of the configuration
     * and leads to the next step.
     *
     * @param FormInterface<ImportMappingData> $form
     * @param callable(): ConfigureImport      $command
     * @param array<string, mixed>             $context what the page shows besides the form
     */
    private function step(Import $import, FormInterface $form, Request $request, string $step, string $next, callable $command, array $context = []): Response
    {
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch($command());

                return $this->redirectToRoute($next, ['id' => $import->getId()]);
            } catch (ImportException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('admin/import/'.$step.'.html.twig', ['import' => $import, 'form' => $form] + $context + [
            'manifest' => ImportManifest::fromArray($import->getManifest()),
            'analysis' => ImportAnalysis::fromArray($import->getAnalysis()),
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /** The configuration steps are for an import between its analysis and its run. */
    private function unlessConfiguring(Import $import): ?Response
    {
        return ImportStatus::Configuring === $import->getStatus() ? null : $this->redirectToRoute('app_admin_import', ['id' => $import->getId()]);
    }

    private function size(int $bytes): string
    {
        return $this->translator->trans('attachment.unit.mb', ['%size%' => max(1, (int) round($bytes / 1024 ** 2))]);
    }
}

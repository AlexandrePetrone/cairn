<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\ConfigRegistry;
use App\Message\Query\Config\CountMembershipsByRole;
use App\Message\Query\Config\ShowWorkflowUsage;
use App\Message\QueryBus;
use App\Repository\ConfigSnapshotRepository;
use App\Repository\TrackerRepository;
use App\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The configuration in force, explained and read-only: it changes in the
 * instance file, applied by app:config:sync. One route per tab. What only
 * the configuration knows is read from the definition by the templates;
 * what the database holds (memberships, trackers, issues) comes from
 * queries.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/configuration')]
final class ConfigurationController extends AbstractController
{
    public function __construct(
        private readonly ConfigRegistry $config,
        private readonly QueryBus $queries,
    ) {
    }

    #[Route('', name: 'app_admin_configuration', methods: ['GET'])]
    public function home(): RedirectResponse
    {
        return $this->redirectToRoute('app_admin_configuration_roles');
    }

    #[Route('/roles', name: 'app_admin_configuration_roles', methods: ['GET'])]
    public function roles(): Response
    {
        return $this->render('admin/configuration/roles.html.twig', [
            'definition' => $this->config->definition(),
            'permissions' => Permission::cases(),
            'memberships' => $this->queries->ask(new CountMembershipsByRole()),
        ]);
    }

    #[Route('/workflows', name: 'app_admin_configuration_workflows', methods: ['GET'])]
    public function workflows(TrackerRepository $trackers): Response
    {
        return $this->render('admin/configuration/workflows.html.twig', [
            'definition' => $this->config->definition(),
            'trackers' => $trackers->countByWorkflow(),
        ]);
    }

    #[Route('/workflows/{id}', name: 'app_admin_configuration_workflow', requirements: ['id' => '[a-z0-9_]+'], methods: ['GET'])]
    public function workflow(string $id): Response
    {
        $definition = $this->config->definition();
        if (!$definition->hasWorkflow($id)) {
            throw new NotFoundHttpException(\sprintf('No workflow "%s" in the configuration.', $id));
        }

        return $this->render('admin/configuration/workflow.html.twig', [
            'definition' => $definition,
            'workflow' => $definition->workflow($id),
            'usage' => $this->queries->ask(new ShowWorkflowUsage($id)),
        ]);
    }

    #[Route('/fields', name: 'app_admin_configuration_fields', methods: ['GET'])]
    public function fields(): Response
    {
        return $this->render('admin/configuration/fields.html.twig', ['definition' => $this->config->definition()]);
    }

    #[Route('/history', name: 'app_admin_configuration_history', methods: ['GET'])]
    public function history(ConfigSnapshotRepository $snapshots): Response
    {
        return $this->render('admin/configuration/history.html.twig', [
            'definition' => $this->config->definition(),
            'snapshots' => $snapshots->findAllLatestFirst(),
        ]);
    }
}

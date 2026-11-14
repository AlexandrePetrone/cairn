<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Workspace;
use App\Form\Workspace\WorkspaceData;
use App\Form\Workspace\WorkspaceType;
use App\Message\Command\Workspace\CreateWorkspace;
use App\Message\Command\Workspace\UpdateWorkspace;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjects;
use App\Message\Query\Workspace\ListWorkspaces;
use App\Message\QueryBus;
use App\MessageHandler\Command\Workspace\WorkspaceException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The workspaces: groups of projects whose memberships apply to all of
 * them. A workspace page has a route per tab: its projects, its
 * memberships, its settings (name, archived).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/workspaces')]
final class WorkspaceController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_admin_workspaces', methods: ['GET'])]
    public function list(#[MapQueryParameter] string $q = ''): Response
    {
        $projects = [];
        foreach ($this->queries->ask(new ListProjects()) as $project) {
            $projects[$project->getWorkspace()->getKey()][] = $project;
        }

        return $this->render('admin/workspaces.html.twig', [
            'q' => $q,
            'workspaces' => $this->queries->ask(new ListWorkspaces($q)),
            'projects' => $projects,
        ]);
    }

    #[Route('/new', name: 'app_admin_workspace_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new WorkspaceData();
        $form = $this->createForm(WorkspaceType::class, $data, ['creation' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $workspace = $this->commands->dispatch(new CreateWorkspace((string) $data->name, (string) $data->key));
                \assert($workspace instanceof Workspace);
                $this->addFlash('success', $this->translator->trans('admin.workspace.created', ['%name%' => $workspace->getName()]));

                return $this->redirectToRoute('app_admin_workspace', ['key' => $workspace->getKey()]);
            } catch (WorkspaceException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('admin/workspace_new.html.twig', ['form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{key}', name: 'app_admin_workspace', requirements: ['key' => '[A-Z]{2,5}'], methods: ['GET'])]
    public function projects(#[MapEntity(mapping: ['key' => 'key'])] Workspace $workspace): Response
    {
        return $this->render('admin/workspace/projects.html.twig', [
            'workspace' => $workspace,
            'projects' => $this->queries->ask(new ListProjects(workspaceId: $workspace->getId())),
        ]);
    }

    #[Route('/{key}/access', name: 'app_admin_workspace_access', requirements: ['key' => '[A-Z]{2,5}'], methods: ['GET'])]
    public function access(#[MapEntity(mapping: ['key' => 'key'])] Workspace $workspace): Response
    {
        return $this->render('admin/workspace/access.html.twig', ['workspace' => $workspace]);
    }

    #[Route('/{key}/settings', name: 'app_admin_workspace_settings', requirements: ['key' => '[A-Z]{2,5}'], methods: ['GET', 'POST'])]
    public function settings(#[MapEntity(mapping: ['key' => 'key'])] Workspace $workspace, Request $request): Response
    {
        $data = new WorkspaceData();
        $data->name = $workspace->getName();
        $data->archived = $workspace->isArchived();
        $form = $this->createForm(WorkspaceType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->commands->dispatch(new UpdateWorkspace((int) $workspace->getId(), (string) $data->name, $data->archived));
            $this->addFlash('success', $this->translator->trans('admin.saved'));

            return $this->redirectToRoute('app_admin_workspace_settings', ['key' => $workspace->getKey()]);
        }

        return $this->render('admin/workspace/settings.html.twig', ['workspace' => $workspace, 'form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }
}

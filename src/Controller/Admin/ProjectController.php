<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Project\CurrentProject;
use App\Entity\Project;
use App\Form\Admin\DeletionData;
use App\Form\Admin\DeletionType;
use App\Form\Project\ProjectData;
use App\Form\Project\ProjectFilters;
use App\Form\Project\ProjectType;
use App\Message\Command\Project\CreateProject;
use App\Message\Command\Project\DeleteProject;
use App\Message\Command\Project\UpdateProject;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjects;
use App\Message\Query\Project\ShowProjectContents;
use App\Message\Query\Workspace\ListWorkspaces;
use App\Message\QueryBus;
use App\MessageHandler\Command\Project\ProjectException;
use App\Repository\ProjectTemplateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every project of the instance, grouped by workspace: creating one,
 * archiving it or bringing it back, deleting an archived one for good.
 */
#[IsGranted('ROLE_ADMIN')]
final class ProjectController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin', name: 'app_admin', methods: ['GET'])]
    public function home(): RedirectResponse
    {
        return $this->redirectToRoute('app_admin_projects');
    }

    #[Route('/admin/projects', name: 'app_admin_projects', methods: ['GET'])]
    public function list(
        #[MapQueryString]
        ProjectFilters $filters = new ProjectFilters(),
    ): Response {
        $groups = [];
        foreach ($this->queries->ask(new ListProjects(q: $filters->q, state: $filters->state)) as $project) {
            $groups[$project->getWorkspace()->getKey()][] = $project;
        }

        return $this->render('admin/projects.html.twig', [
            'filters' => $filters,
            'groups' => $groups,
        ]);
    }

    /**
     * `?workspace=MD` preselects the workspace, from the page of one. A
     * template, when the instance has some, gives the project its
     * trackers and its automation rules.
     */
    #[Route('/admin/projects/new', name: 'app_admin_project_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ProjectTemplateRepository $templates): Response
    {
        $workspaces = $this->queries->ask(new ListWorkspaces(archived: false));
        $data = new ProjectData();
        foreach ($workspaces as $workspace) {
            if ($workspace->getKey() === $request->query->getString('workspace')) {
                $data->workspace = $workspace;
            }
        }

        $form = $this->createForm(ProjectType::class, $data, ['workspaces' => $workspaces, 'templates' => $templates->findAllByName()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && null !== $data->workspace) {
            try {
                $project = $this->commands->dispatch(new CreateProject((int) $data->workspace->getId(), (string) $data->name, (string) $data->key, $data->template?->getId()));
                \assert($project instanceof Project);
                $this->addFlash('success', $this->translator->trans('admin.project.created', ['%name%' => $project->getName()]));

                return $this->redirectToRoute('app_admin_workspace', ['key' => $project->getWorkspace()->getKey()]);
            } catch (ProjectException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('admin/project_new.html.twig', ['form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Archives the project or brings it back (`archived`), from a list.
     */
    #[Route('/admin/projects/{workspaceKey}/{projectKey}', name: 'app_admin_project_update', methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function update(#[CurrentProject] Project $project, Request $request): RedirectResponse
    {
        $archived = $request->getPayload()->getBoolean('archived');
        $this->commands->dispatch(new UpdateProject((int) $project->getId(), archived: $archived));
        $this->addFlash('success', $this->translator->trans($archived ? 'admin.project.archived' : 'admin.project.unarchived', ['%name%' => $project->getName()]));

        return $this->back($request);
    }

    /**
     * What the project holds, and the form that deletes it for good once
     * its key is typed again: for an archived project only.
     */
    #[Route('/admin/projects/{workspaceKey}/{projectKey}/delete', name: 'app_admin_project_delete', methods: ['GET', 'POST'])]
    public function delete(#[CurrentProject] Project $project, Request $request): Response
    {
        if (!$project->isReadOnly()) {
            throw $this->createNotFoundException('Only an archived project is deleted.');
        }
        $data = new DeletionData();
        $form = $this->createForm(DeletionType::class, $data, ['expected' => $project->getIssuePrefix()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $name = $project->getName();
            $workspaceKey = $project->getWorkspace()->getKey();
            try {
                $this->commands->dispatch(new DeleteProject((int) $project->getId()));
                $this->addFlash('success', $this->translator->trans('admin.project.deleted', ['%name%' => $name]));

                return $this->redirectToRoute('app_admin_workspace', ['key' => $workspaceKey]);
            } catch (ProjectException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('admin/project_delete.html.twig', [
            'project' => $project,
            'contents' => $this->queries->ask(new ShowProjectContents($project)),
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Back to the administration page the button was on (`back`), the
     * project list otherwise.
     */
    private function back(Request $request): RedirectResponse
    {
        $back = $request->getPayload()->getString('back');

        return str_starts_with($back, '/admin/') ? $this->redirect($back) : $this->redirectToRoute('app_admin_projects');
    }
}

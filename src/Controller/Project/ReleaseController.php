<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Enum\ReleaseStatus;
use App\Entity\Project;
use App\Entity\Release;
use App\Entity\User;
use App\Form\Release\ReleaseData;
use App\Form\Release\ReleaseType;
use App\Message\Command\Release\CreateRelease;
use App\Message\Command\Release\UpdateRelease;
use App\Message\CommandBus;
use App\Message\Query\Release\ListReleases;
use App\Message\QueryBus;
use App\MessageHandler\Command\Release\ReleaseException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The releases of a project: everyone who sees the project reads them,
 * with their progress; `release.manage` creates, changes, delivers and
 * abandons them. Issues are scheduled in a release from their own page or
 * the selection page.
 */
#[Route('/{workspaceKey}/{projectKey}/releases', requirements: ['workspaceKey' => '[A-Z]{2,5}', 'projectKey' => '[A-Z]{2,5}'])]
#[IsGranted('view', subject: 'project')]
final class ReleaseController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_project_releases', methods: ['GET', 'POST'])]
    public function list(#[CurrentProject] Project $project, #[CurrentUser] User $viewer, Request $request): Response
    {
        $form = null;
        if ($this->isGranted('release.manage', $project)) {
            $data = new ReleaseData();
            $form = $this->createForm(ReleaseType::class, $data);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                try {
                    $release = $this->commands->dispatch(new CreateRelease((int) $viewer->getId(), (int) $project->getId(), (string) $data->name, $data->plannedOn, $data->description));
                    \assert($release instanceof Release);
                    $this->addFlash('success', $this->translator->trans('release.created', ['%name%' => $release->getName()]));

                    return $this->redirectToRoute('app_project_releases', self::route($project));
                } catch (ReleaseException $e) {
                    $form->addError(new FormError($e->trans($this->translator)));
                }
            }
        } elseif ($request->isMethod('POST')) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('project/releases.html.twig', [
            'project' => $project,
            'summaries' => $this->queries->ask(new ListReleases($viewer, $project)),
            'form' => $form,
        ], new Response(null, $form?->isSubmitted() ? 422 : 200));
    }

    #[Route('/{release}', name: 'app_project_release_edit', requirements: ['release' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('release.manage', subject: 'project')]
    public function edit(#[CurrentProject] Project $project, #[MapEntity(id: 'release')] Release $release, #[CurrentUser] User $actor, Request $request): Response
    {
        $this->assertOfProject($release, $project);
        $data = new ReleaseData();
        $data->name = $release->getName();
        $data->plannedOn = $release->getPlannedOn();
        $data->description = $release->getDescription();
        $form = $this->createForm(ReleaseType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new UpdateRelease(
                    (int) $actor->getId(),
                    (int) $release->getId(),
                    name: (string) $data->name,
                    plannedOn: $data->plannedOn,
                    unplanned: null === $data->plannedOn,
                    description: (string) $data->description,
                ));
                $this->addFlash('success', $this->translator->trans('release.saved'));

                return $this->redirectToRoute('app_project_releases', self::route($project));
            } catch (ReleaseException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('project/release_edit.html.twig', [
            'project' => $project,
            'release' => $release,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Delivers, abandons or reopens the release (`status`).
     */
    #[Route('/{release}/status', name: 'app_project_release_status', requirements: ['release' => '\d+'], methods: ['POST'])]
    #[IsGranted('release.manage', subject: 'project')]
    #[IsCsrfTokenValid('submit')]
    public function status(#[CurrentProject] Project $project, #[MapEntity(id: 'release')] Release $release, #[CurrentUser] User $actor, Request $request): RedirectResponse
    {
        $this->assertOfProject($release, $project);
        $status = ReleaseStatus::tryFrom($request->getPayload()->getString('status')) ?? throw new NotFoundHttpException('Unknown release status.');
        $this->commands->dispatch(new UpdateRelease((int) $actor->getId(), (int) $release->getId(), status: $status));
        $this->addFlash('success', $this->translator->trans('release.status_changed.'.$status->value, ['%name%' => $release->getName()]));

        return $this->redirectToRoute('app_project_releases', self::route($project));
    }

    private function assertOfProject(Release $release, Project $project): void
    {
        if ($release->getProject()->getId() !== $project->getId()) {
            throw new NotFoundHttpException(\sprintf('Release %d is not a release of %s.', $release->getId(), $project->getIssuePrefix()));
        }
    }

    /**
     * @return array{workspaceKey: string, projectKey: string}
     */
    private static function route(Project $project): array
    {
        return ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey()];
    }
}

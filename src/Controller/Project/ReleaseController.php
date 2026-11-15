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
use App\Message\Command\Release\DeleteRelease;
use App\Message\Command\Release\UpdateRelease;
use App\Message\CommandBus;
use App\Message\Query\Release\ListReleases;
use App\Message\QueryBus;
use App\MessageHandler\Command\Release\ReleaseException;
use App\Repository\IssueRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
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
 * abandons them, in a dialog that has an address of its own and renders
 * the list with it open. Issues are scheduled in a release from their own page or
 * the selection page.
 */
#[Route('/{workspaceKey}/{projectKey}/releases', requirements: ['workspaceKey' => '[A-Z]{2,5}', 'projectKey' => '[A-Z]{2,5}'])]
#[IsGranted('view', subject: 'project')]
final class ReleaseController extends AbstractController
{
    use OpensDialog;

    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly IssueRepository $issues,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_project_releases', methods: ['GET'])]
    public function list(#[CurrentProject] Project $project, #[CurrentUser] User $viewer, Request $request): Response
    {
        return $this->renderList($request, $project, $viewer, null, null);
    }

    /**
     * The releases with the dialog creating one.
     */
    #[Route('/new', name: 'app_project_release_new', methods: ['GET', 'POST'])]
    #[IsGranted('release.manage', subject: 'project')]
    public function new(#[CurrentProject] Project $project, #[CurrentUser] User $actor, Request $request): Response
    {
        $data = new ReleaseData();
        // sent to the address of the dialog, not to the page it is loaded over
        $form = $this->createForm(ReleaseType::class, $data, ['action' => $request->getRequestUri()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $release = $this->commands->dispatch(new CreateRelease((int) $actor->getId(), (int) $project->getId(), (string) $data->name, $data->plannedOn, $data->description));
                \assert($release instanceof Release);
                $this->addFlash('success', $this->translator->trans('release.created', ['%name%' => $release->getName()]));

                return $this->redirectToRoute('app_project_releases', self::route($project));
            } catch (ReleaseException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->renderList($request, $project, $actor, $form, null);
    }

    /**
     * The releases with the dialog changing one: its name, date and
     * description, abandoning it while it is open, and deleting it.
     */
    #[Route('/{release}', name: 'app_project_release_edit', requirements: ['release' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('release.manage', subject: 'project')]
    public function edit(#[CurrentProject] Project $project, #[MapEntity(id: 'release')] Release $release, #[CurrentUser] User $actor, Request $request): Response
    {
        $this->assertOfProject($release, $project);
        $data = new ReleaseData();
        $data->name = $release->getName();
        $data->plannedOn = $release->getPlannedOn();
        $data->description = $release->getDescription();
        // sent to the address of the dialog, not to the page it is loaded over
        $form = $this->createForm(ReleaseType::class, $data, ['action' => $request->getRequestUri()]);
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

        return $this->renderList($request, $project, $actor, $form, $release);
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

    /**
     * Deletes the release; the issues it held have none any more.
     */
    #[Route('/{release}/delete', name: 'app_project_release_delete', requirements: ['release' => '\d+'], methods: ['POST'])]
    #[IsGranted('release.manage', subject: 'project')]
    #[IsCsrfTokenValid('submit')]
    public function delete(#[CurrentProject] Project $project, #[MapEntity(id: 'release')] Release $release, #[CurrentUser] User $actor): RedirectResponse
    {
        $this->assertOfProject($release, $project);
        $name = $release->getName();
        $this->commands->dispatch(new DeleteRelease((int) $actor->getId(), (int) $release->getId()));
        $this->addFlash('success', $this->translator->trans('release.deleted', ['%name%' => $name]));

        return $this->redirectToRoute('app_project_releases', self::route($project));
    }

    /**
     * @param FormInterface<ReleaseData>|null $form    the dialog, when it is open
     * @param Release|null                    $release the release it changes, none when it creates one
     */
    private function renderList(Request $request, Project $project, User $viewer, ?FormInterface $form, ?Release $release): Response
    {
        return $this->renderWithDialog($request, 'project/releases.html.twig', [
            'project' => $project,
            'form' => $form,
            'dialogRelease' => $release,
            'dialogIssueCount' => null === $release ? 0 : $this->issues->count(['release' => $release]),
        ], fn (): array => [
            'summaries' => $this->queries->ask(new ListReleases($viewer, $project)),
        ], $form);
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

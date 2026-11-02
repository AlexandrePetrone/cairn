<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Config\ConfigRegistry;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Form\ProjectSettingsData;
use App\Form\ProjectSettingsType;
use App\Form\TrackerData;
use App\Form\TrackerType;
use App\Message\Command\Project\UpdateProject;
use App\Message\Command\Tracker\CreateTracker;
use App\Message\Command\Tracker\UpdateTracker;
use App\Message\CommandBus;
use App\MessageHandler\Command\Tracker\TrackerException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The configuration of a project, for its manager: its trackers (the list
 * with the form of a new one, or the settings of one) and its general
 * settings. Archiving stays in the administration.
 */
#[Route('/{workspaceKey}/{projectKey}/settings', requirements: ['workspaceKey' => '[A-Z]{2,5}', 'projectKey' => '[A-Z]{2,5}'])]
#[IsGranted('project.configure', subject: 'project')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly ConfigRegistry $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_project_settings', methods: ['GET', 'POST'])]
    public function trackers(#[CurrentProject] Project $project, #[CurrentUser] User $actor, Request $request): Response
    {
        $data = new TrackerData();
        $form = $this->createForm(TrackerType::class, $data, ['creation' => true, 'workflows' => $this->config->definition()->workflowLabels()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $tracker = $this->commands->dispatch(new CreateTracker((int) $actor->getId(), (int) $project->getId(), (string) $data->name, (string) $data->workflowId, $data->description, $data->private));
                \assert($tracker instanceof Tracker);
                $this->addFlash('success', $this->translator->trans('project.settings.tracker.created', ['%name%' => $tracker->getName()]));

                return $this->redirectToRoute('app_project_settings_tracker', self::route($project) + ['tracker' => $tracker->getId()]);
            } catch (TrackerException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('project/settings/trackers.html.twig', ['project' => $project, 'form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/trackers/{tracker}', name: 'app_project_settings_tracker', requirements: ['tracker' => '\d+'], methods: ['GET', 'POST'])]
    public function tracker(#[CurrentProject] Project $project, #[MapEntity(id: 'tracker')] Tracker $tracker, #[CurrentUser] User $actor, Request $request): Response
    {
        if ($tracker->getProject()->getId() !== $project->getId()) {
            throw new NotFoundHttpException();
        }
        $data = new TrackerData();
        $data->name = $tracker->getName();
        $data->description = $tracker->getDescription();
        $data->private = $tracker->isPrivate();
        $data->active = $tracker->isActive();
        $form = $this->createForm(TrackerType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->commands->dispatch(new UpdateTracker((int) $actor->getId(), (int) $tracker->getId(), (string) $data->name, (string) $data->description, $data->private, $data->active));
            $this->addFlash('success', $this->translator->trans('project.settings.saved'));

            return $this->redirectToRoute('app_project_settings_tracker', self::route($project) + ['tracker' => $tracker->getId()]);
        }

        $definition = $this->config->definition();

        return $this->render('project/settings/tracker.html.twig', [
            'project' => $project,
            'tracker' => $tracker,
            'workflow' => $definition->hasWorkflow($tracker->getWorkflowId()) ? $definition->workflow($tracker->getWorkflowId()) : null,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/general', name: 'app_project_settings_general', methods: ['GET', 'POST'])]
    public function general(#[CurrentProject] Project $project, #[CurrentUser] User $actor, Request $request): Response
    {
        $data = new ProjectSettingsData();
        $data->name = $project->getName();
        $data->description = $project->getDescription();
        $form = $this->createForm(ProjectSettingsType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->commands->dispatch(new UpdateProject((int) $project->getId(), (string) $data->name, (string) $data->description, actorId: (int) $actor->getId()));
            $this->addFlash('success', $this->translator->trans('project.settings.saved'));

            return $this->redirectToRoute('app_project_settings_general', self::route($project));
        }

        return $this->render('project/settings/general.html.twig', ['project' => $project, 'form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * @return array{workspaceKey: string, projectKey: string}
     */
    private static function route(Project $project): array
    {
        return ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey()];
    }
}

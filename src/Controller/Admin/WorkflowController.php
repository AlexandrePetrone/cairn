<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Automation\RuleReferences;
use App\Config\ConfigRegistry;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\RoleDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Workflow;
use App\Form\Config\AddPlacesData;
use App\Form\Config\AddPlacesType;
use App\Form\Config\TransitionData;
use App\Form\Config\TransitionType;
use App\Form\Config\WorkflowData;
use App\Form\Config\WorkflowPlaceData;
use App\Form\Config\WorkflowPlaceType;
use App\Form\Config\WorkflowType;
use App\Message\Command\Config\AddWorkflowPlace;
use App\Message\Command\Config\CreateTransition;
use App\Message\Command\Config\CreateWorkflow;
use App\Message\Command\Config\DeleteTransition;
use App\Message\Command\Config\DeleteWorkflow;
use App\Message\Command\Config\RemoveWorkflowPlace;
use App\Message\Command\Config\UpdateTransition;
use App\Message\Command\Config\UpdateWorkflow;
use App\Message\CommandBus;
use App\Message\Query\Config\ShowWorkflowUsage;
use App\Message\QueryBus;
use App\MessageHandler\Command\Config\ConfigDeletions;
use App\Repository\IssueRepository;
use App\Repository\TrackerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The workflows: their list, and the page of each (its diagram and the
 * categories that follow it on two pages beside it), where its steps, its
 * actions and the fields it asks for are arranged in place (Config:Order,
 * Config:WorkflowFields) and changed in dialogs that have an address of
 * their own. Taking a step out of a workflow is the one change that
 * touches issues: its dialog says how many stand there, asks where they
 * go and lists the actions that go or change with the step.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/configuration')]
final class WorkflowController extends AbstractController
{
    use EditsConfiguration;

    private const array ID = ['id' => '[a-z][a-z0-9_]*'];
    private const array KEYS = ['id' => '[a-z][a-z0-9_]*', 'key' => '[a-z][a-z0-9_]*'];

    public function __construct(
        private readonly ConfigRegistry $config,
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly IssueRepository $issues,
        private readonly RuleReferences $rules,
        private readonly TranslatorInterface $translator,
        private readonly ConfigDeletions $deletions,
    ) {
    }

    #[Route('/workflows', name: 'app_admin_configuration_workflows', methods: ['GET'])]
    public function list(Request $request, TrackerRepository $trackers): Response
    {
        return $this->index($request, $trackers, null);
    }

    #[Route('/workflows/new', name: 'app_admin_configuration_workflow_new', methods: ['GET', 'POST'])]
    public function new(Request $request, TrackerRepository $trackers): Response
    {
        $data = new WorkflowData();
        $form = $this->createForm(WorkflowType::class, $data, ['action' => $request->getRequestUri(), 'places' => self::choices($this->config->definition()->places)]);
        $form->handleRequest($request);
        if ($this->applies($form, static fn (): CreateWorkflow => new CreateWorkflow((string) $data->label, (string) $data->initial), $workflow)) {
            \assert($workflow instanceof Workflow);
            $this->addFlash('success', $this->translator->trans('admin.configuration.workflows.created', ['%name%' => $workflow->getLabel()]));

            return $this->redirectToRoute('app_admin_configuration_workflow', ['id' => $workflow->getId()]);
        }

        return $this->index($request, $trackers, $form);
    }

    #[Route('/workflow/{id}', name: 'app_admin_configuration_workflow', requirements: self::ID, methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        return $this->page($request, $this->workflow($id), null, 'none');
    }

    /**
     * The diagram of the workflow, on a page of its own.
     */
    #[Route('/workflow/{id}/diagram', name: 'app_admin_configuration_workflow_diagram', requirements: self::ID, methods: ['GET'])]
    public function diagram(string $id): Response
    {
        return $this->render('admin/configuration/workflow_diagram.html.twig', ['definition' => $this->config->definition(), 'workflow' => $this->workflow($id)]);
    }

    /**
     * The categories that follow the workflow, with their issues step by step.
     */
    #[Route('/workflow/{id}/usage', name: 'app_admin_configuration_workflow_usage', requirements: self::ID, methods: ['GET'])]
    public function usage(string $id): Response
    {
        return $this->render('admin/configuration/workflow_usage.html.twig', [
            'definition' => $this->config->definition(),
            'workflow' => $this->workflow($id),
            'usage' => $this->queries->ask(new ShowWorkflowUsage($id)),
        ]);
    }

    /**
     * The dialog of the workflow itself: its name, its initial step, and its deletion.
     */
    #[Route('/workflow/{id}/edit', name: 'app_admin_configuration_workflow_edit', requirements: self::ID, methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $workflow = $this->workflow($id);
        $data = new WorkflowData();
        $data->label = $workflow->label;
        $data->initial = $workflow->initial;

        $form = $this->createForm(WorkflowType::class, $data, ['action' => $request->getRequestUri(), 'places' => self::choices($this->config->definition()->placesOf($workflow))]);
        $form->handleRequest($request);
        if ($this->applies($form, static fn (): UpdateWorkflow => new UpdateWorkflow($id, (string) $data->label, $data->initial))) {
            return $this->saved($id);
        }

        return $this->page($request, $workflow, $form, 'workflow', ['dialogRefusal' => $this->deletions->workflow($id)?->trans($this->translator)]);
    }

    #[Route('/workflow/{id}/delete', name: 'app_admin_configuration_workflow_delete', requirements: self::ID, methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function delete(string $id): RedirectResponse
    {
        $this->attempt(new DeleteWorkflow($id), $this->translator->trans('admin.configuration.workflows.deleted', ['%name%' => $this->workflow($id)->label]));

        return $this->config->definition()->hasWorkflow($id)
            ? $this->redirectToRoute('app_admin_configuration_workflow', ['id' => $id])
            : $this->redirectToRoute('app_admin_configuration_workflows');
    }

    /**
     * The dialog that adds steps of the instance to the workflow, several at once.
     */
    #[Route('/workflow/{id}/places/add', name: 'app_admin_configuration_workflow_place_add', requirements: self::ID, methods: ['GET', 'POST'])]
    public function addPlace(string $id, Request $request): Response
    {
        $workflow = $this->workflow($id);
        $others = array_filter($this->config->definition()->places, static fn (PlaceDefinition $place): bool => !$workflow->hasPlace($place->id));
        if ([] === $others) {
            // Nothing left to add: the page does not offer the dialog.
            throw new NotFoundHttpException(\sprintf('The workflow "%s" already uses every step of the instance.', $id));
        }
        $data = new AddPlacesData();

        $form = $this->createForm(AddPlacesType::class, $data, ['action' => $request->getRequestUri(), 'places' => self::choices($others)]);
        $form->handleRequest($request);
        // One command per step, in the order of the instance: each comes after the columns the kanban has.
        if ($this->applies($form, static fn (): array => array_map(static fn (string $place): AddWorkflowPlace => new AddWorkflowPlace($id, $place), array_values($data->places)))) {
            return $this->saved($id);
        }

        return $this->page($request, $workflow, $form, 'place_add');
    }

    /**
     * The dialog that takes a step out of the workflow, saying what goes with it.
     */
    #[Route('/workflow/{id}/place/{place}/remove', name: 'app_admin_configuration_workflow_place_remove', requirements: self::ID + ['place' => '[a-z][a-z0-9_]*'], methods: ['GET', 'POST'])]
    public function removePlace(string $id, string $place, Request $request): Response
    {
        $definition = $this->config->definition();
        $workflow = $this->workflow($id);
        if (!$workflow->hasPlace($place) || $place === $workflow->initial) {
            throw new NotFoundHttpException(\sprintf('"%s" is not a place the workflow "%s" may lose.', $place, $id));
        }
        $standing = $this->issues->countOnPlace($place, $id);
        $others = array_filter($definition->placesOf($workflow), static fn (PlaceDefinition $other): bool => $other->id !== $place);
        $data = new WorkflowPlaceData();

        $form = $this->createForm(WorkflowPlaceType::class, $data, [
            'action' => $request->getRequestUri(),
            'places' => self::choices($others),
            'label_key' => 'admin.configuration.workflow.form.target',
            'help_key' => 'admin.configuration.workflow.form.target_help',
            'place_required' => $standing > 0,
        ]);
        $form->handleRequest($request);
        if ($this->applies($form, static fn (): RemoveWorkflowPlace => new RemoveWorkflowPlace($id, $place, $data->place))) {
            $this->addFlash('success', $this->translator->trans('admin.configuration.workflow.place_removed', ['%place%' => $definition->place($place)->label, '%count%' => $standing]));

            return $this->redirectToRoute('app_admin_configuration_workflow', ['id' => $id]);
        }

        // What RemoveWorkflowPlace does to the actions, said before it is done.
        $deleted = [];
        $changed = [];
        foreach ($workflow->transitions as $transition) {
            if ($transition->to === $place || [$place] === $transition->from) {
                $deleted[] = $transition->label;
            } elseif (\in_array($place, $transition->from, true)) {
                $changed[] = $transition->label;
            }
        }

        return $this->page($request, $workflow, $form, 'place_remove', [
            'dialogPlace' => $definition->place($place),
            'dialogStanding' => $standing,
            'dialogDeleted' => $deleted,
            'dialogChanged' => $changed,
            'dialogRules' => $this->rules->toPlace($id, $place),
        ]);
    }

    #[Route('/workflow/{id}/actions/new', name: 'app_admin_configuration_transition_new', requirements: self::ID, methods: ['GET', 'POST'])]
    public function newTransition(string $id, Request $request): Response
    {
        $workflow = $this->workflow($id);
        $data = new TransitionData();
        $form = $this->transitionForm($request, $workflow, $data);
        if ($this->applies($form, static fn (): CreateTransition => new CreateTransition($id, (string) $data->label, $data->from, (string) $data->to, $data->roles, $data->style, $data->requiresComment, $data->commentPrompt, $data->description))) {
            return $this->saved($id);
        }

        return $this->page($request, $workflow, $form, 'transition');
    }

    #[Route('/workflow/{id}/action/{key}', name: 'app_admin_configuration_transition', requirements: self::KEYS, methods: ['GET', 'POST'])]
    public function editTransition(string $id, string $key, Request $request): Response
    {
        $workflow = $this->workflow($id);
        $transition = $workflow->transitions[$key] ?? throw new NotFoundHttpException(\sprintf('No action "%s" in the workflow "%s".', $key, $id));
        $data = new TransitionData();
        $data->label = $transition->label;
        $data->from = $transition->from;
        $data->to = $transition->to;
        $data->roles = $transition->roles;
        $data->style = $transition->style;
        $data->requiresComment = $transition->requiresComment;
        $data->commentPrompt = $transition->commentPrompt;
        $data->description = $transition->description;

        $form = $this->transitionForm($request, $workflow, $data);
        // An empty prompt or description erases it.
        if ($this->applies($form, static fn (): UpdateTransition => new UpdateTransition($id, $key, (string) $data->label, $data->from, $data->to, $data->roles, $data->style, $data->requiresComment, (string) $data->commentPrompt, (string) $data->description))) {
            return $this->saved($id);
        }

        return $this->page($request, $workflow, $form, 'transition', ['dialogTransition' => $transition, 'dialogRules' => $this->rules->toTransition($id, $key)]);
    }

    #[Route('/workflow/{id}/action/{key}/delete', name: 'app_admin_configuration_transition_delete', requirements: self::KEYS, methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function deleteTransition(string $id, string $key): RedirectResponse
    {
        $this->attempt(new DeleteTransition($id, $key), $this->translator->trans('admin.configuration.action.deleted'));

        return $this->redirectToRoute('app_admin_configuration_workflow', ['id' => $id]);
    }

    /**
     * @return FormInterface<TransitionData>
     */
    private function transitionForm(Request $request, WorkflowDefinition $workflow, TransitionData $data): FormInterface
    {
        $definition = $this->config->definition();
        $form = $this->createForm(TransitionType::class, $data, [
            'action' => $request->getRequestUri(),
            'places' => self::choices($definition->placesOf($workflow)),
            'roles' => self::choices($definition->roles),
        ]);
        $form->handleRequest($request);

        return $form;
    }

    private function saved(string $id): RedirectResponse
    {
        $this->addFlash('success', $this->translator->trans('admin.configuration.saved'));

        return $this->redirectToRoute('app_admin_configuration_workflow', ['id' => $id]);
    }

    /**
     * The list of the workflows, with the dialog that creates one when it is open.
     *
     * @param FormInterface<WorkflowData>|null $form
     */
    private function index(Request $request, TrackerRepository $trackers, ?FormInterface $form): Response
    {
        return $this->renderWithDialog($request, 'admin/configuration/workflows.html.twig', ['form' => $form], fn (): array => [
            'definition' => $this->config->definition(),
            'trackers' => $trackers->countByWorkflow(),
        ], $form);
    }

    /**
     * The page of a workflow, with one of its dialogs when it is open.
     *
     * @param FormInterface<mixed>|null $form
     * @param string                    $dialog  which dialog: `workflow`, `place_add`, `place_remove`, `transition`, or `none`
     * @param array<string, mixed>      $details what that dialog shows beside its form
     */
    private function page(Request $request, WorkflowDefinition $workflow, ?FormInterface $form, string $dialog, array $details = []): Response
    {
        return $this->renderWithDialog($request, 'admin/configuration/workflow.html.twig', ['form' => $form, 'workflow' => $workflow, 'dialog' => $dialog] + $details, fn (): array => [
            'definition' => $this->config->definition(),
        ], $form);
    }

    private function workflow(string $id): WorkflowDefinition
    {
        $definition = $this->config->definition();

        return $definition->hasWorkflow($id) ? $definition->workflow($id) : throw new NotFoundHttpException(\sprintf('No workflow "%s" in the configuration.', $id));
    }

    /**
     * @param iterable<PlaceDefinition|RoleDefinition> $items
     *
     * @return array<string, string> label => id, for a choice; two items of one label keep both their ids
     */
    private static function choices(iterable $items): array
    {
        $choices = [];
        foreach ($items as $item) {
            $choices[isset($choices[$item->label]) ? \sprintf('%s (%s)', $item->label, $item->id) : $item->label] = $item->id;
        }

        return $choices;
    }
}

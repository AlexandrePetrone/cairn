<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Budget\Quantities;
use App\Controller\OpensDialog;
use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Enum\BudgetUnit;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\User;
use App\Form\Budget\BudgetData;
use App\Form\Budget\BudgetLineData;
use App\Form\Budget\BudgetLineType;
use App\Form\Budget\BudgetType;
use App\Message\Command\Budget\AddBudgetLine;
use App\Message\Command\Budget\CreateBudget;
use App\Message\Command\Budget\DeleteBudget;
use App\Message\Command\Budget\RemoveBudgetLine;
use App\Message\Command\Budget\UpdateBudget;
use App\Message\Command\Budget\UpdateBudgetLine;
use App\Message\CommandBus;
use App\Message\Query\Budget\ListBudgets;
use App\Message\Query\Issue\SearchIssues;
use App\Message\QueryBus;
use App\MessageHandler\Command\Budget\BudgetException;
use App\Repository\IssueRepository;
use App\Security\AccessControl;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The budgets of a project that has some (it has a unit): whoever holds
 * `budget.view` or `budget.manage` reads them on one page, the open ones
 * with all of their lines, and each on a page of its own, which is where
 * a closed one is read; `budget.manage` creates and changes the budgets,
 * deletes a closed one, and writes their lines, in dialogs that have an
 * address of their own.
 * The status of a line is also marked in place, by `Budget:Card`. Nothing
 * here touches the issues the lines name.
 */
#[Route('/{workspaceKey}/{projectKey}/budgets', requirements: ['workspaceKey' => '[A-Z]{2,5}', 'projectKey' => '[A-Z]{2,5}', 'budget' => '\d+', 'line' => '\d+'])]
#[IsGranted('view', subject: 'project')]
final class BudgetController extends AbstractController
{
    use OpensDialog;

    /** How many issues the form of a line offers at once, the open ones first; the others are found by typing. */
    private const int ISSUES_OFFERED = 20;

    /** What the card of the page of a budget puts in the address of its dialogs (`from`): they lead back to that page. */
    private const string FROM_ITS_PAGE = 'budget';

    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly Quantities $quantities,
        private readonly AccessControl $access,
        private readonly IssueRepository $issues,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_project_budgets', methods: ['GET'])]
    public function list(#[CurrentProject] Project $project, Request $request): Response
    {
        return $this->renderPage($request, $project);
    }

    /**
     * The budgets with the dialog creating one.
     */
    #[Route('/new', name: 'app_project_budget_new', methods: ['GET', 'POST'])]
    #[IsGranted('budget.manage', subject: 'project')]
    public function new(#[CurrentProject] Project $project, #[CurrentUser] User $actor, Request $request): Response
    {
        $unit = $this->unit($project);
        $data = new BudgetData();
        // sent to the address of the dialog, not to the page it is loaded over
        $form = $this->createForm(BudgetType::class, $data, ['action' => $request->getRequestUri(), 'unit' => $unit]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $budget = $this->commands->dispatch(new CreateBudget((int) $actor->getId(), (int) $project->getId(), (string) $data->name, $this->quantities->toMinutes((float) $data->volume, $unit)));
                \assert($budget instanceof Budget);
                $this->addFlash('success', $this->translator->trans('budget.created', ['%name%' => $budget->getName()]));

                return $this->redirectToRoute('app_project_budgets', self::route($project));
            } catch (BudgetException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->renderPage($request, $project, form: $form);
    }

    /**
     * The page of one budget: what it amounts to and its lines.
     */
    #[Route('/{budget}', name: 'app_project_budget', methods: ['GET'])]
    public function show(#[CurrentProject] Project $project, #[MapEntity(id: 'budget')] Budget $budget, Request $request): Response
    {
        $this->assertOfProject($budget, $project);

        return $this->renderPage($request, $project, $budget);
    }

    /**
     * The budgets with the dialog changing one: its name, its volume, and
     * whether it is closed.
     */
    #[Route('/{budget}/edit', name: 'app_project_budget_edit', methods: ['GET', 'POST'])]
    #[IsGranted('budget.manage', subject: 'project')]
    public function edit(#[CurrentProject] Project $project, #[MapEntity(id: 'budget')] Budget $budget, #[CurrentUser] User $actor, Request $request): Response
    {
        $this->assertOfProject($budget, $project);
        $unit = $this->unit($project);
        $data = new BudgetData();
        $data->name = $budget->getName();
        $data->volume = $this->quantities->inUnit($budget->getVolume(), $unit);
        $data->closed = !$budget->isOpen();
        // sent to the address of the dialog, not to the page it is loaded over
        $form = $this->createForm(BudgetType::class, $data, ['action' => $request->getRequestUri(), 'unit' => $unit, 'closable' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new UpdateBudget(
                    (int) $actor->getId(),
                    (int) $budget->getId(),
                    name: (string) $data->name,
                    volume: $this->quantities->fromInput((float) $data->volume, $unit, $budget->getVolume()),
                    closed: $data->closed,
                ));
                $this->addFlash('success', $this->translator->trans('budget.saved'));

                return $this->redirect($this->home($project, self::behind($request, $budget)));
            } catch (BudgetException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->renderPage($request, $project, self::behind($request, $budget), $form, $budget);
    }

    /**
     * Deletes a closed budget with its lines, from the dialog that changes
     * it.
     */
    #[Route('/{budget}/delete', name: 'app_project_budget_delete', methods: ['POST'])]
    #[IsGranted('budget.manage', subject: 'project')]
    #[IsCsrfTokenValid('submit')]
    public function delete(#[CurrentProject] Project $project, #[MapEntity(id: 'budget')] Budget $budget, #[CurrentUser] User $actor): RedirectResponse
    {
        $this->unit($project);
        $this->assertOfProject($budget, $project);
        $name = $budget->getName();
        try {
            $this->commands->dispatch(new DeleteBudget((int) $actor->getId(), (int) $budget->getId()));
        } catch (BudgetException $e) {
            // reopened since the dialog was drawn
            $this->addFlash('error', $e->trans($this->translator));

            return $this->redirect($this->home($project, $budget));
        }
        $this->addFlash('success', $this->translator->trans('budget.deleted', ['%name%' => $name]));

        return $this->redirect($this->home($project, null));
    }

    /**
     * What the form of a line offers as its issue: the issues of the
     * project the manager sees matching what is typed, closed ones too (a
     * line is often written, or put right, once the work is delivered),
     * as the items of its picker, and how many more there are.
     */
    #[Route('/issues', name: 'app_project_budget_issues', methods: ['GET'])]
    #[IsGranted('budget.manage', subject: 'project')]
    public function issues(#[CurrentProject] Project $project, #[CurrentUser] User $actor, #[MapQueryParameter] string $q = ''): Response
    {
        $this->unit($project);
        $page = $this->queries->ask(new SearchIssues($actor, projectId: $project->getId(), q: $q, closed: true, perPage: self::ISSUES_OFFERED));

        return $this->render('project/_budget_issue_choices.html.twig', ['issues' => $page->items, 'more' => $page->total - \count($page->items)]);
    }

    /**
     * The dialog writing a line on a budget: the issue it is for is
     * searched among the issues of the project the manager sees.
     */
    #[Route('/{budget}/lines/new', name: 'app_project_budget_line_new', methods: ['GET', 'POST'])]
    #[IsGranted('budget.manage', subject: 'project')]
    public function newLine(#[CurrentProject] Project $project, #[MapEntity(id: 'budget')] Budget $budget, #[CurrentUser] User $actor, Request $request): Response
    {
        $this->assertOfProject($budget, $project);
        $unit = $this->unit($project);
        $data = new BudgetLineData();
        // sent to the address of the dialog, not to the page it is loaded over
        $form = $this->createForm(BudgetLineType::class, $data, [
            'action' => $request->getRequestUri(),
            'unit' => $unit,
            'issues_url' => $this->generateUrl('app_project_budget_issues', self::route($project)),
            // what the search could have offered, and nothing else: the handler says so again
            'find_issue' => function (string $id) use ($project, $actor): ?Issue {
                // an id is a few digits: a longer number is none, and the database would refuse to compare it
                $issue = ctype_digit($id) && \strlen($id) < 10 ? $this->issues->find((int) $id) : null;

                return null !== $issue && $issue->getProject()->getId() === $project->getId() && $this->access->canSee($actor, $issue) ? $issue : null;
            },
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new AddBudgetLine(
                    (int) $actor->getId(),
                    (int) $budget->getId(),
                    $data->status ?? BudgetLineStatus::ToEstimate,
                    null === $data->quantity ? null : $this->quantities->toMinutes($data->quantity, $unit),
                    $data->issue?->getId(),
                    $data->label,
                ));
                $this->addFlash('success', $this->translator->trans('budget.line.added'));

                return $this->redirect($this->home($project, self::behind($request, $budget)));
            } catch (BudgetException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->renderPage($request, $project, self::behind($request, $budget), $form, $budget, null, 'line');
    }

    /**
     * The dialog changing a line (its status, quantity and label), or
     * deleting it. The menu of a line that has no quantity leads here for
     * a status that needs one (`status`): the status is chosen already, and
     * the quantity is what is asked.
     */
    #[Route('/{budget}/lines/{line}', name: 'app_project_budget_line_edit', methods: ['GET', 'POST'])]
    #[IsGranted('budget.manage', subject: 'project')]
    public function editLine(#[CurrentProject] Project $project, #[MapEntity(id: 'budget')] Budget $budget, #[MapEntity(id: 'line')] BudgetLine $line, #[CurrentUser] User $actor, Request $request): Response
    {
        $this->assertOfBudget($line, $budget, $project);
        $unit = $this->unit($project);
        $data = new BudgetLineData();
        $asked = BudgetLineStatus::tryFrom($request->query->getString('status'));
        $data->label = $line->getLabel();
        $data->status = $asked ?? $line->getStatus();
        $data->quantity = null === $line->getQuantity() ? null : $this->quantities->inUnit($line->getQuantity(), $unit);
        // sent to the address of the dialog, not to the page it is loaded over
        $form = $this->createForm(BudgetLineType::class, $data, ['action' => $request->getRequestUri(), 'unit' => $unit, 'asks_quantity' => null !== $asked]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new UpdateBudgetLine(
                    (int) $actor->getId(),
                    (int) $line->getId(),
                    $data->status,
                    null === $data->quantity ? null : $this->quantities->fromInput($data->quantity, $unit, $line->getQuantity()),
                    (string) $data->label,
                    // an emptied field is no quantity, not "keep the one it had": a line to approve or approved is then refused
                    withoutQuantity: null === $data->quantity,
                ));
                $this->addFlash('success', $this->translator->trans('budget.line.saved'));

                return $this->redirect($this->home($project, self::behind($request, $budget)));
            } catch (BudgetException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->renderPage($request, $project, self::behind($request, $budget), $form, $budget, $line, 'line');
    }

    #[Route('/{budget}/lines/{line}/delete', name: 'app_project_budget_line_delete', methods: ['POST'])]
    #[IsGranted('budget.manage', subject: 'project')]
    #[IsCsrfTokenValid('submit')]
    public function deleteLine(#[CurrentProject] Project $project, #[MapEntity(id: 'budget')] Budget $budget, #[MapEntity(id: 'line')] BudgetLine $line, #[CurrentUser] User $actor, Request $request): RedirectResponse
    {
        $this->unit($project);
        $this->assertOfBudget($line, $budget, $project);
        $this->commands->dispatch(new RemoveBudgetLine((int) $actor->getId(), (int) $line->getId()));
        $this->addFlash('success', $this->translator->trans('budget.line.removed'));

        return $this->redirect($this->home($project, self::behind($request, $budget)));
    }

    /**
     * A page of the budgets, with the dialog its address opens: the page of
     * `$shown`, or the budgets of the project.
     *
     * @param FormInterface<mixed>|null $form         the dialog, when the address opens one
     * @param Budget|null               $dialogBudget the budget it changes, or writes a line on
     * @param BudgetLine|null           $dialogLine   the line it changes
     * @param 'budget'|'line'           $dialogFor    what it writes
     */
    private function renderPage(Request $request, Project $project, ?Budget $shown = null, ?FormInterface $form = null, ?Budget $dialogBudget = null, ?BudgetLine $dialogLine = null, string $dialogFor = 'budget'): Response
    {
        $this->assertReads($project);
        $dialog = [
            'project' => $project,
            'form' => $form,
            'dialogFor' => $dialogFor,
            'dialogBudget' => $dialogBudget,
            'dialogLine' => $dialogLine,
            'dialogHome' => $this->home($project, $shown),
            'dialogFrom' => null === $shown ? null : self::FROM_ITS_PAGE,
        ];
        if (null !== $shown) {
            return $this->renderWithDialog($request, 'project/budget.html.twig', $dialog, static fn (): array => ['budget' => $shown], $form);
        }

        return $this->renderWithDialog($request, 'project/budgets.html.twig', $dialog, fn (): array => [
            'summaries' => $this->queries->ask(new ListBudgets($project)),
        ], $form);
    }

    /**
     * The page a dialog of a budget was opened from, where it leads back:
     * the page of the budget when its address says so (`from=budget`, which
     * the card of that page puts in its links), none otherwise, which
     * stands for the budgets page.
     */
    private static function behind(Request $request, Budget $budget): ?Budget
    {
        return self::FROM_ITS_PAGE === $request->query->get('from') ? $budget : null;
    }

    /**
     * The address of the page of a budget, or of the budgets page.
     */
    private function home(Project $project, ?Budget $shown): string
    {
        return $this->generateUrl(null === $shown ? 'app_project_budgets' : 'app_project_budget', self::route($project, $shown));
    }

    /**
     * The pages exist on a project that has budgets, for whoever reads or
     * manages them.
     */
    private function assertReads(Project $project): void
    {
        $this->unit($project);
        $viewer = $this->getUser();
        if (!$viewer instanceof User || !$this->access->canReadBudgets($viewer, $project)) {
            throw $this->createAccessDeniedException('Reading the budgets needs budget.view.');
        }
    }

    private function unit(Project $project): BudgetUnit
    {
        return $project->getBudgetUnit() ?? throw new NotFoundHttpException(\sprintf('%s has no budget.', $project->getIssuePrefix()));
    }

    private function assertOfProject(Budget $budget, Project $project): void
    {
        if ($budget->getProject()->getId() !== $project->getId()) {
            throw new NotFoundHttpException(\sprintf('Budget %d is not a budget of %s.', $budget->getId(), $project->getIssuePrefix()));
        }
    }

    private function assertOfBudget(BudgetLine $line, Budget $budget, Project $project): void
    {
        $this->assertOfProject($budget, $project);
        if ($line->getBudget()->getId() !== $budget->getId()) {
            throw new NotFoundHttpException(\sprintf('Line %d is not a line of budget %d.', $line->getId(), $budget->getId()));
        }
    }

    /**
     * @return array{workspaceKey: string, projectKey: string, budget?: int}
     */
    private static function route(Project $project, ?Budget $budget = null): array
    {
        $route = ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey()];

        return null === $budget ? $route : $route + ['budget' => (int) $budget->getId()];
    }
}

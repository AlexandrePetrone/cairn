<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Form\BoardFilters;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\QueryBus;
use App\Repository\ReleaseRepository;
use App\Workflow\WorkflowProvider;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The issue lists of a project, one route per need. Each route fixes the
 * scope of the list and the filters it offers; Issue:List searches, and
 * reads the filter values from the query string. A tracker is also shown
 * as a kanban, one column per place of its workflow.
 */
#[Route('/{workspaceKey}/{projectKey}/issues', requirements: ['workspaceKey' => '[A-Z]{2,5}', 'projectKey' => '[A-Z]{2,5}'])]
#[IsGranted('view', subject: 'project')]
final class IssueListController extends AbstractController
{
    #[Route('', name: 'app_project_issues', methods: ['GET'])]
    public function all(#[CurrentProject] Project $project): Response
    {
        return $this->list($project, 'all', ['place', 'tracker', 'criticality', 'assignee', 'release']);
    }

    #[Route('/assigned', name: 'app_project_issues_assigned', methods: ['GET'])]
    public function assigned(#[CurrentProject] Project $project, #[CurrentUser] User $viewer): Response
    {
        return $this->list($project, 'assigned', ['place', 'tracker', 'criticality', 'release'], assigneeId: $viewer->getId());
    }

    #[Route('/created', name: 'app_project_issues_created', methods: ['GET'])]
    public function created(#[CurrentProject] Project $project, #[CurrentUser] User $viewer): Response
    {
        return $this->list($project, 'created', ['place', 'tracker', 'criticality', 'assignee', 'release'], authorId: $viewer->getId());
    }

    /**
     * The issues of one tracker. Trackers have no key, so the URL carries
     * their id; a tracker of another project, or a private one asked for by
     * an external user, is not found.
     */
    #[Route('/tracker/{tracker}', name: 'app_project_issues_tracker', requirements: ['tracker' => '\\d+'], methods: ['GET'])]
    public function tracker(
        #[CurrentProject]
        Project $project,
        #[MapEntity]
        Tracker $tracker,
        #[CurrentUser]
        User $viewer,
    ): Response {
        $this->assertTrackerOf($tracker, $project, $viewer);

        return $this->list($project, 'tracker', ['place', 'criticality', 'assignee', 'release'], tracker: $tracker);
    }

    /**
     * The kanban of a tracker: a column per place, each loading its issues
     * when it shows and by batches (Issue:BoardColumn). The filters are a
     * GET form, so a filtered board is an address and every column starts
     * again from its first batch.
     */
    #[Route('/tracker/{tracker}/board', name: 'app_project_board', requirements: ['tracker' => '\\d+'], methods: ['GET'])]
    public function board(
        #[CurrentProject]
        Project $project,
        #[MapEntity]
        Tracker $tracker,
        #[CurrentUser]
        User $viewer,
        WorkflowProvider $workflows,
        QueryBus $queries,
        ReleaseRepository $releases,
        #[MapQueryString(validationFailedStatusCode: 404)]
        BoardFilters $filters = new BoardFilters(),
    ): Response {
        $this->assertTrackerOf($tracker, $project, $viewer);

        return $this->render('project/board.html.twig', [
            'project' => $project,
            'tracker' => $tracker,
            'workflow' => $workflows->definition($tracker->getWorkflowId()),
            'filters' => $filters,
            'members' => $queries->ask(new ListProjectMembers($project)),
            'releases' => $releases->findByProject($project),
        ]);
    }

    /**
     * A tracker of another project, or a private one asked for by an
     * external user, is not found.
     */
    private function assertTrackerOf(Tracker $tracker, Project $project, User $viewer): void
    {
        if ($tracker->getProject()->getId() !== $project->getId() || ($tracker->isPrivate() && !$viewer->isInternal())) {
            throw new NotFoundHttpException(\sprintf('Tracker %d is not a tracker of %s.', $tracker->getId(), $project->getIssuePrefix()));
        }
    }

    /**
     * @param list<string> $dimensions the filters the list offers, among place, tracker, criticality, assignee and release
     */
    private function list(Project $project, string $scope, array $dimensions, ?Tracker $tracker = null, ?int $assigneeId = null, ?int $authorId = null): Response
    {
        return $this->render('project/issues.html.twig', [
            'project' => $project,
            'scope' => $scope,
            'tracker' => $tracker,
            'dimensions' => $dimensions,
            'assigneeId' => $assigneeId,
            'authorId' => $authorId,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Controller\ShowsIssueList;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Twig\Components\IssueList\ListScope;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The issue lists of a project, one route per need. Each route fixes the
 * scope of the list and the filters it offers (ListScope); the address
 * says how it is arranged, and ShowsIssueList answers. The list of a
 * tracker also shows as a kanban, one column per place of its workflow:
 * a view of the same list, under the same filters.
 */
#[Route('/{workspaceKey}/{projectKey}/issues', requirements: ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT])]
#[IsGranted('view', subject: 'project')]
final class IssueListController extends AbstractController
{
    use ShowsIssueList;

    #[Route('', name: 'app_project_issues', methods: ['GET', 'PUT'])]
    public function all(Request $request, #[CurrentProject] Project $project, #[CurrentUser] User $viewer): Response
    {
        return $this->list($request, $viewer, 'all', new ListScope($request->getBaseUrl().$request->getPathInfo(), ['place', 'tracker', 'criticality', 'assignee', 'release', 'tag'], $project));
    }

    #[Route('/assigned', name: 'app_project_issues_assigned', methods: ['GET', 'PUT'])]
    public function assigned(Request $request, #[CurrentProject] Project $project, #[CurrentUser] User $viewer): Response
    {
        return $this->list($request, $viewer, 'assigned', new ListScope($request->getBaseUrl().$request->getPathInfo(), ['place', 'tracker', 'criticality', 'release', 'tag'], $project, assigneeId: $viewer->getId()));
    }

    #[Route('/created', name: 'app_project_issues_created', methods: ['GET', 'PUT'])]
    public function created(Request $request, #[CurrentProject] Project $project, #[CurrentUser] User $viewer): Response
    {
        return $this->list($request, $viewer, 'created', new ListScope($request->getBaseUrl().$request->getPathInfo(), ['place', 'tracker', 'criticality', 'assignee', 'release', 'tag'], $project, authorId: $viewer->getId()));
    }

    /**
     * The issues of one tracker, as a list or as a kanban (`view`).
     * Trackers have no key, so the URL carries their id; a tracker of
     * another project, or a private one asked for by an external user, is
     * not found.
     */
    #[Route('/tracker/{tracker}', name: 'app_project_issues_tracker', requirements: ['tracker' => '\\d+'], methods: ['GET', 'PUT'])]
    public function tracker(
        Request $request,
        #[CurrentProject]
        Project $project,
        #[MapEntity]
        Tracker $tracker,
        #[CurrentUser]
        User $viewer,
    ): Response {
        if ($tracker->getProject()->getId() !== $project->getId() || ($tracker->isPrivate() && !$viewer->isInternal())) {
            throw new NotFoundHttpException(\sprintf('Tracker %d is not a tracker of %s.', $tracker->getId(), $project->getIssuePrefix()));
        }

        return $this->list($request, $viewer, 'tracker', ListScope::ofTracker($tracker, $request->getBaseUrl().$request->getPathInfo()));
    }

    private function list(Request $request, User $viewer, string $list, ListScope $scope): Response
    {
        return $this->issueList($request, $viewer, $scope, 'project/issues.html.twig', ['project' => $scope->project, 'list' => $list]);
    }
}

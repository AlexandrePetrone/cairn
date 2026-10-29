<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Form\IssueFilters;
use App\Message\Query\Issue\SearchIssues;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\QueryBus;
use App\Repository\TrackerRepository;
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
 * scope of the search and the filters it offers; the filter values come
 * from the query string.
 */
#[Route('/{workspaceKey}/{projectKey}/issues', requirements: ['workspaceKey' => '[A-Z]{2,5}', 'projectKey' => '[A-Z]{2,5}'])]
#[IsGranted('view', subject: 'project')]
final class IssueListController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly TrackerRepository $trackers,
    ) {
    }

    #[Route('', name: 'app_project_issues', methods: ['GET'])]
    public function all(
        #[CurrentProject]
        Project $project,
        #[CurrentUser]
        User $viewer,
        #[MapQueryString]
        IssueFilters $filters = new IssueFilters(),
    ): Response {
        return $this->list($project, $filters, 'all', ['status', 'tracker', 'criticality', 'assignee'], self::search($viewer, $project, $filters));
    }

    #[Route('/assigned', name: 'app_project_issues_assigned', methods: ['GET'])]
    public function assigned(
        #[CurrentProject]
        Project $project,
        #[CurrentUser]
        User $viewer,
        #[MapQueryString]
        IssueFilters $filters = new IssueFilters(),
    ): Response {
        return $this->list($project, $filters, 'assigned', ['status', 'tracker', 'criticality'], self::search($viewer, $project, $filters, assigneeId: $viewer->getId()));
    }

    #[Route('/created', name: 'app_project_issues_created', methods: ['GET'])]
    public function created(
        #[CurrentProject]
        Project $project,
        #[CurrentUser]
        User $viewer,
        #[MapQueryString]
        IssueFilters $filters = new IssueFilters(),
    ): Response {
        return $this->list($project, $filters, 'created', ['status', 'tracker', 'criticality', 'assignee'], self::search($viewer, $project, $filters, authorId: $viewer->getId()));
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
        #[MapQueryString]
        IssueFilters $filters = new IssueFilters(),
    ): Response {
        if ($tracker->getProject()->getId() !== $project->getId() || ($tracker->isPrivate() && !$viewer->isInternal())) {
            throw new NotFoundHttpException(\sprintf('Tracker %d is not a tracker of %s.', $tracker->getId(), $project->getIssuePrefix()));
        }

        return $this->list($project, $filters, 'tracker', ['status', 'criticality', 'assignee'], self::search($viewer, $project, $filters, trackerId: $tracker->getId()), $tracker);
    }

    /**
     * @param list<string> $dimensions the filters the list offers, among status, tracker, criticality and assignee
     * @param Tracker|null $tracker    the tracker the list is scoped to, which names it
     */
    private function list(Project $project, IssueFilters $filters, string $scope, array $dimensions, SearchIssues $query, ?Tracker $tracker = null): Response
    {
        return $this->render('project/issues.html.twig', [
            'project' => $project,
            'scope' => $scope,
            'tracker' => $tracker,
            'dimensions' => $dimensions,
            'filters' => $filters,
            'issues' => $this->queries->ask($query),
            'trackers' => $this->trackers->findByProject($project, includePrivate: $query->viewer->isInternal()),
            'members' => \in_array('assignee', $dimensions, true) ? $this->queries->ask(new ListProjectMembers($project)) : [],
            'statuses' => PlaceStatus::cases(),
            'criticalities' => Criticality::cases(),
        ]);
    }

    /**
     * The scope comes from the route, the filter values from the query string.
     */
    private static function search(User $viewer, Project $project, IssueFilters $filters, ?int $assigneeId = null, ?int $authorId = null, ?int $trackerId = null): SearchIssues
    {
        return new SearchIssues(
            viewer: $viewer,
            projectId: $project->getId(),
            assigneeId: $assigneeId,
            authorId: $authorId,
            status: $filters->status,
            tracker: null === $trackerId ? $filters->tracker : [$trackerId],
            assignee: $filters->assignee,
            criticality: $filters->criticality,
            q: $filters->q,
            closed: $filters->closed,
            page: $filters->page,
        );
    }
}

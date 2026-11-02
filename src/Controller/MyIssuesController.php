<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\PlaceStatus;
use App\Entity\User;
use App\Form\IssueFilters;
use App\Message\Query\Issue\SearchIssues;
use App\Message\Query\Project\ListVisibleProjects;
use App\Message\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The issues of the signed-in user across every project they see: those
 * assigned to them, those they created. One route per scope, the filter
 * values from the query string.
 */
#[Route('/issues')]
final class MyIssuesController extends AbstractController
{
    private const array DIMENSIONS = ['status', 'project', 'criticality'];

    public function __construct(
        private readonly QueryBus $queries,
    ) {
    }

    #[Route('/assigned', name: 'app_my_issues_assigned', methods: ['GET'])]
    public function assigned(
        #[CurrentUser]
        User $viewer,
        #[MapQueryString]
        IssueFilters $filters = new IssueFilters(),
    ): Response {
        return $this->list($viewer, $filters, 'assigned', self::search($viewer, $filters, assigneeId: $viewer->getId()));
    }

    #[Route('/created', name: 'app_my_issues_created', methods: ['GET'])]
    public function created(
        #[CurrentUser]
        User $viewer,
        #[MapQueryString]
        IssueFilters $filters = new IssueFilters(),
    ): Response {
        return $this->list($viewer, $filters, 'created', self::search($viewer, $filters, authorId: $viewer->getId()));
    }

    private function list(User $viewer, IssueFilters $filters, string $scope, SearchIssues $query): Response
    {
        return $this->render('my_issues.html.twig', [
            'scope' => $scope,
            'dimensions' => self::DIMENSIONS,
            'filters' => $filters,
            'issues' => $this->queries->ask($query),
            'projects' => $this->queries->ask(new ListVisibleProjects($viewer)),
            'statuses' => PlaceStatus::cases(),
            'criticalities' => Criticality::cases(),
        ]);
    }

    private static function search(User $viewer, IssueFilters $filters, ?int $assigneeId = null, ?int $authorId = null): SearchIssues
    {
        return new SearchIssues(
            viewer: $viewer,
            assigneeId: $assigneeId,
            authorId: $authorId,
            status: $filters->status,
            project: $filters->project,
            criticality: $filters->criticality,
            q: $filters->q,
            closed: $filters->closed,
            page: $filters->page,
        );
    }
}

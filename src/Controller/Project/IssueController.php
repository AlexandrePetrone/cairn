<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Project;
use App\Entity\User;
use App\Message\Query\Issue\ShowIssue;
use App\Message\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The issue page. An issue the viewer may not see is not found, so that
 * private issues cannot be probed.
 */
final class IssueController extends AbstractController
{
    #[Route(
        '/{workspaceKey}/{projectKey}/issues/{number}',
        name: 'app_issue_show',
        requirements: ['workspaceKey' => '[A-Z]{2,5}', 'projectKey' => '[A-Z]{2,5}', 'number' => '\d+'],
        methods: ['GET'],
    )]
    #[IsGranted('view', subject: 'project')]
    public function __invoke(
        #[CurrentProject]
        Project $project,
        #[CurrentUser]
        User $viewer,
        int $number,
        QueryBus $queries,
    ): Response {
        $detail = $queries->ask(new ShowIssue($viewer, (int) $project->getId(), $number))
            ?? throw $this->createNotFoundException(\sprintf('No issue %s-%d for this viewer.', $project->getIssuePrefix(), $number));

        return $this->render('project/issue.html.twig', ['project' => $project, 'detail' => $detail]);
    }
}

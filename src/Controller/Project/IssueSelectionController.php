<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Project;
use App\Entity\Workspace;
use App\Form\Issue\IssueSelectionRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The bulk actions on the issues checked in a list of the project: the
 * same page as the application's (issue/selection.html.twig), in the
 * project's layout, coming back to the list, or to the project's issues.
 */
#[Route('/{workspaceKey}/{projectKey}/issues/selection', requirements: ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT])]
#[IsGranted('view', subject: 'project')]
final class IssueSelectionController extends AbstractController
{
    #[Route('', name: 'app_project_issues_selection', methods: ['GET'])]
    public function __invoke(
        #[CurrentProject]
        Project $project,
        #[MapQueryString]
        IssueSelectionRequest $selection = new IssueSelectionRequest(),
    ): Response {
        return $this->render('issue/selection.html.twig', [
            'project' => $project,
            'ids' => $selection->ids(),
            'back' => $selection->from() ?? $this->generateUrl('app_project_issues', ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey()]),
        ]);
    }
}

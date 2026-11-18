<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Project;
use App\Entity\Workspace;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The new issue page. The screen itself, tracker choice and form, is the
 * Issue:CreateForm live component, which dispatches the command. The
 * voter refuses archived projects, which take no new issues.
 */
final class CreateIssueController extends AbstractController
{
    #[Route(
        '/{workspaceKey}/{projectKey}/issues/new',
        name: 'app_issue_new',
        requirements: ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT],
        methods: ['GET'],
    )]
    #[IsGranted('issue.create', subject: 'project')]
    public function __invoke(
        #[CurrentProject]
        Project $project,
    ): Response {
        return $this->render('project/issue_new.html.twig', ['project' => $project]);
    }
}

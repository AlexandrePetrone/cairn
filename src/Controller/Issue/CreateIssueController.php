<?php

declare(strict_types=1);

namespace App\Controller\Issue;

use App\Entity\Project;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The new issue page. The screen itself, tracker choice and form, is the
 * Issue:CreateForm live component, which dispatches the command.
 */
final class CreateIssueController extends AbstractController
{
    #[Route(
        '/{workspaceKey}/{projectKey}/issues/new',
        name: 'app_issue_new',
        requirements: ['workspaceKey' => '[A-Z]{2,5}', 'projectKey' => '[A-Z]{2,5}'],
        methods: ['GET'],
    )]
    #[IsGranted('issue.create', subject: 'project')]
    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByKeys(workspaceKey, projectKey)')]
        Project $project,
    ): Response {
        if ($project->isArchived()) {
            throw $this->createAccessDeniedException('Archived projects take no new issues.');
        }

        return $this->render('issue/new.html.twig', ['project' => $project]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Project;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Who has access to a project: the roles inherited from its workspace,
 * read-only here, and the roles held on the project, which its manager
 * grants, changes and revokes through Membership:Grants.
 */
final class AccessController extends AbstractController
{
    #[Route(
        '/{workspaceKey}/{projectKey}/access',
        name: 'app_project_access',
        requirements: ['workspaceKey' => '[A-Z]{2,5}', 'projectKey' => '[A-Z]{2,5}'],
        methods: ['GET'],
    )]
    #[IsGranted('project.manage_members', subject: 'project')]
    public function __invoke(#[CurrentProject] Project $project): Response
    {
        return $this->render('project/access.html.twig', ['project' => $project]);
    }
}

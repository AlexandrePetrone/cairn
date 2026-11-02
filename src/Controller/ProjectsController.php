<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Message\Query\Project\ListVisibleProjects;
use App\Message\Query\Project\ProjectSummary;
use App\Message\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Every project the signed-in user may open, grouped by workspace. A
 * workspace is only a grouping here: it is managed in the administration.
 */
final class ProjectsController extends AbstractController
{
    #[Route('/projects', name: 'app_projects', methods: ['GET'])]
    public function __invoke(
        #[CurrentUser]
        User $viewer,
        QueryBus $queries,
        #[MapQueryParameter]
        string $q = '',
    ): Response {
        return $this->render('projects.html.twig', [
            'groups' => ProjectSummary::byWorkspace($queries->ask(new ListVisibleProjects($viewer, $q))),
            'q' => $q,
        ]);
    }
}

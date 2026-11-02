<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\PlaceStatus;
use App\Entity\User;
use App\Message\Query\Issue\SearchIssues;
use App\Message\Query\Project\ListVisibleProjects;
use App\Message\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Landing page after sign-in, across every project: the projects that
 * moved last, what to look at first (the viewer's issues waiting, the
 * blocking ones assigned to them), then the first of their issues,
 * assigned and created, each leading to its full list.
 */
final class HomeController extends AbstractController
{
    private const int PROJECTS = 8;

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $viewer, QueryBus $queries): Response
    {
        return $this->render('home.html.twig', [
            'projects' => $queries->ask(new ListVisibleProjects($viewer, recent: self::PROJECTS)),
            'waiting' => $queries->ask(new SearchIssues($viewer, authorId: $viewer->getId(), status: [PlaceStatus::Waiting]))->items,
            'blocking' => $queries->ask(new SearchIssues($viewer, assigneeId: $viewer->getId(), criticality: [Criticality::Blocking]))->items,
            'assigned' => $queries->ask(new SearchIssues($viewer, assigneeId: $viewer->getId())),
            'created' => $queries->ask(new SearchIssues($viewer, authorId: $viewer->getId())),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The issues of the signed-in user across every project they see: those
 * assigned to them, those they created. One route per scope; Issue:List
 * searches, and reads the filter values from the query string.
 */
#[Route('/issues')]
final class MyIssuesController extends AbstractController
{
    private const array DIMENSIONS = ['place', 'project', 'criticality'];

    #[Route('/assigned', name: 'app_my_issues_assigned', methods: ['GET'])]
    public function assigned(#[CurrentUser] User $viewer): Response
    {
        return $this->render('my_issues.html.twig', ['scope' => 'assigned', 'dimensions' => self::DIMENSIONS, 'assigneeId' => $viewer->getId(), 'authorId' => null]);
    }

    #[Route('/created', name: 'app_my_issues_created', methods: ['GET'])]
    public function created(#[CurrentUser] User $viewer): Response
    {
        return $this->render('my_issues.html.twig', ['scope' => 'created', 'dimensions' => self::DIMENSIONS, 'assigneeId' => null, 'authorId' => $viewer->getId()]);
    }
}

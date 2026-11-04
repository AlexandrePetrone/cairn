<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\IssueSelectionRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The bulk actions on the issues checked in a list across projects (my
 * issues): Issue:Selection shows them and what may be done to all of
 * them, then goes back to the list, or home. A project's lists have their
 * own selection page, in the project's layout
 * (Project/IssueSelectionController).
 */
final class IssueSelectionController extends AbstractController
{
    #[Route('/issues/selection', name: 'app_issues_selection', methods: ['GET'])]
    public function __invoke(
        #[MapQueryString]
        IssueSelectionRequest $selection = new IssueSelectionRequest(),
    ): Response {
        return $this->render('issue/selection.html.twig', [
            'ids' => $selection->ids(),
            'back' => $selection->from() ?? $this->generateUrl('app_home'),
        ]);
    }
}

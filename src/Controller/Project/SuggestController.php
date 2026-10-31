<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\User;
use App\Message\Query\Issue\SearchIssues;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What the editor offers after `#` (issues of the project the user may
 * see, closed ones included) and after `@` (the project's members).
 */
#[Route('/{workspaceKey}/{projectKey}/suggest', requirements: ['workspaceKey' => '[A-Z]{2,5}', 'projectKey' => '[A-Z]{2,5}'])]
#[IsGranted('view', subject: 'project')]
final class SuggestController extends AbstractController
{
    private const int LIMIT = 8;

    #[Route('/issues', name: 'app_suggest_issues', methods: ['GET'])]
    public function issues(
        #[CurrentProject]
        Project $project,
        #[CurrentUser]
        User $viewer,
        QueryBus $queries,
        #[MapQueryParameter]
        string $q = '',
    ): JsonResponse {
        $page = $queries->ask(new SearchIssues($viewer, projectId: $project->getId(), q: $q, closed: true));

        return $this->json(array_map(
            static fn (Issue $issue) => ['key' => $issue->getKey(), 'title' => $issue->getTitle()],
            \array_slice($page->items, 0, self::LIMIT),
        ));
    }

    #[Route('/users', name: 'app_suggest_users', methods: ['GET'])]
    public function users(
        #[CurrentProject]
        Project $project,
        QueryBus $queries,
        #[MapQueryParameter]
        string $q = '',
    ): JsonResponse {
        $text = mb_strtolower(trim($q));
        $members = array_filter(
            $queries->ask(new ListProjectMembers($project)),
            static fn (User $user) => '' === $text || str_contains(mb_strtolower($user->getFullName()), $text),
        );

        return $this->json(array_map(
            static fn (User $user) => ['id' => $user->getId(), 'name' => $user->getFullName()],
            \array_slice(array_values($members), 0, self::LIMIT),
        ));
    }
}

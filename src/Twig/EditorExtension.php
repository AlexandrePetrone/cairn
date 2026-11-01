<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Project;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * `editor_urls(project)`: the endpoints an editor (Ui:Form:Editor) uses in
 * a project, for `#` and `@` suggestions and pasted images. The one place
 * that knows them; Form/Type/MarkdownType asks here too.
 */
final class EditorExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{issues: string, users: string, upload: string}
     */
    #[AsTwigFunction('editor_urls')]
    public function urls(Project $project): array
    {
        $route = ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey()];

        return [
            'issues' => $this->urls->generate('app_suggest_issues', $route),
            'users' => $this->urls->generate('app_suggest_users', $route),
            'upload' => $this->urls->generate('app_attachment_upload', $route),
        ];
    }
}

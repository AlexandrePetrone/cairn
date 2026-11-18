<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Project;
use App\Markdown\MarkdownRenderer;
use App\Security\Viewer;
use Twig\Attribute\AsTwigFilter;

/**
 * `{{ comment.body|markdown }}`: the text written by users, rendered for
 * the current reader.
 */
final class MarkdownExtension
{
    public function __construct(
        private readonly MarkdownRenderer $renderer,
        private readonly Viewer $viewer,
    ) {
    }

    #[AsTwigFilter('markdown', isSafe: ['html'])]
    public function markdown(string $markdown, Project $project): string
    {
        return $this->renderer->render($markdown, $this->viewer->get(), $project);
    }
}

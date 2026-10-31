<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Markdown\MarkdownRenderer;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFilter;

/**
 * `{{ comment.body|markdown }}`: the text written by users, rendered for
 * the current reader.
 */
final class MarkdownExtension
{
    public function __construct(
        private readonly MarkdownRenderer $renderer,
        private readonly Security $security,
    ) {
    }

    #[AsTwigFilter('markdown', isSafe: ['html'])]
    public function markdown(string $markdown): string
    {
        $reader = $this->security->getUser();
        if (!$reader instanceof User) {
            throw new \LogicException('Markdown is rendered for a signed-in reader.');
        }

        return $this->renderer->render($markdown, $reader);
    }
}

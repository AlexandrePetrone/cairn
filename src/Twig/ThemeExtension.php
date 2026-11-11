<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * The theme chosen in the user menu, kept by the browser in a cookie that
 * the `theme` controller writes: the server renders <html> with it, so that
 * a page never shows in the other theme before switching. Without a choice,
 * the theme is the system's.
 */
final class ThemeExtension
{
    public const string COOKIE = 'cairn_theme';

    /** The choices a person can make; the system's is the absence of one. */
    public const array CHOICES = ['light', 'dark'];

    public function __construct(
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * `light`, `dark`, or `system` when nothing (or nothing known) was chosen.
     */
    #[AsTwigFunction('theme_choice')]
    public function choice(): string
    {
        $value = $this->requests->getMainRequest()?->cookies->get(self::COOKIE);

        return \in_array($value, self::CHOICES, true) ? $value : 'system';
    }
}

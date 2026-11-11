<?php

declare(strict_types=1);

namespace App\Tests\Assets;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Templates, Stimulus controllers, PHP classes and Storia views colour with
 * the roles of app.css (`bg-surface`, `text-muted`, `bg-red-soft`), never
 * with a step of the Tailwind palette nor white grounds, which would stay
 * the same in the dark theme. Allowed:
 * slate, the colour of what is dark in both themes (code, the header, which
 * is exempt as a whole with its amber and indigo), white text on a solid
 * fill, and the white knob of a toggle. Mails have their own inline styles.
 */
final class PaletteClassesTest extends TestCase
{
    private const string PALETTE = '/(?<![\w-])(?:bg|text|border(?:-[trblxy])?|ring|ring-offset|divide|outline|decoration|placeholder|fill|stroke|from|via|to|caret|accent|shadow)-(?:(?:gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}|white)(?:\/\d+)?(?![\w-])/';

    /** @var array<string, list<string>> path => classes allowed there; '*' for every file */
    private const array ALLOWED = [
        '*' => ['text-white'],
        'components/Ui/Form/Toggle.html.twig' => ['bg-white'],
    ];

    private const array EXEMPT = ['components/Layout/Header.html.twig', 'email/'];

    public function testNothingColoursWithAStepOfThePalette(): void
    {
        $found = [];
        $root = \dirname(__DIR__, 2).'/';
        $files = (new Finder())->files()->in([$root.'templates', $root.'assets/controllers', $root.'src', $root.'storia'])->name(['*.twig', '*.js', '*.php', '*.yaml']);
        foreach ($files as $file) {
            $path = substr($file->getRealPath(), \strlen($root));
            $path = preg_replace('#^templates/#', '', $path) ?? $path;
            if (array_filter(self::EXEMPT, static fn (string $exempt): bool => str_starts_with($path, $exempt))) {
                continue;
            }
            $allowed = array_merge(self::ALLOWED['*'], self::ALLOWED[$path] ?? []);
            preg_match_all(self::PALETTE, $file->getContents(), $matches);
            foreach (array_diff($matches[0], $allowed) as $class) {
                $found[] = $path.': '.$class;
            }
        }

        self::assertSame([], $found, 'Use a colour role of app.css instead.');
    }
}

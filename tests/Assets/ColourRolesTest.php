<?php

declare(strict_types=1);

namespace App\Tests\Assets;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The colour roles of app.css, read from the file: each text role keeps its
 * contrast on every ground it is written on, in both themes (WCAG 2 ratio).
 * A new role comes with its pairs here.
 */
final class ColourRolesTest extends TestCase
{
    private const string CSS = __DIR__.'/../../assets/styles/app.css';

    /** Where text is written: the page, cards, menus, hovered rows, rails. */
    private const array GROUNDS = ['canvas', 'surface', 'overlay', 'subtle', 'sunken'];

    private const array TONES = ['blue', 'amber', 'red', 'emerald', 'violet', 'orange', 'sky'];

    /** @var array<string, array{light: string, dark: string}>|null */
    private static ?array $roles = null;

    /**
     * @return iterable<string, array{string, string, float}>
     */
    public static function pairs(): iterable
    {
        foreach (self::GROUNDS as $ground) {
            foreach (['strong', 'body', 'muted', 'brand'] as $text) {
                yield "$text on $ground" => [$text, $ground, 4.5];
            }
            // Icons, counts and dates beside a text that says the same. Below AA on purpose, as the
            // grey-400 it replaces was: this floor only keeps it from fading further.
            yield "faint on $ground" => ['faint', $ground, 2.5];
        }
        foreach (self::TONES as $tone) {
            yield "$tone on $tone-soft" => [$tone, "$tone-soft", 4.5];
            yield "$tone on surface" => [$tone, 'surface', 4.5];
        }
        yield 'brand on brand-soft' => ['brand', 'brand-soft', 4.5];
        yield 'on-inverse on inverse' => ['on-inverse', 'inverse', 4.5];
        yield 'focus on surface' => ['focus', 'surface', 3.0];
    }

    #[DataProvider('pairs')]
    public function testTheTextReadsOnItsGroundInBothThemes(string $text, string $ground, float $minimum): void
    {
        foreach (['light', 'dark'] as $theme) {
            $ratio = self::ratio(self::role($text)[$theme], self::role($ground)[$theme]);
            self::assertGreaterThanOrEqual($minimum, $ratio, \sprintf('%s on %s in the %s theme: %.2f:1.', $text, $ground, $theme, $ratio));
        }
    }

    public function testWhiteReadsOnTheSolidFillsItIsWrittenOn(): void
    {
        foreach (['brand-solid', 'brand-solid-hover'] as $fill) {
            foreach (['light', 'dark'] as $theme) {
                self::assertGreaterThanOrEqual(4.5, self::ratio('#ffffff', self::role($fill)[$theme]), "White on $fill, $theme theme.");
            }
        }
    }

    /**
     * @return array{light: string, dark: string}
     */
    private static function role(string $name): array
    {
        return self::roles()[$name] ?? throw new \LogicException(\sprintf('No colour role "%s" in app.css.', $name));
    }

    /**
     * @return array<string, array{light: string, dark: string}>
     */
    private static function roles(): array
    {
        if (null === self::$roles) {
            $css = (string) file_get_contents(self::CSS);
            preg_match_all('/^\s*--color-([a-z-]+):\s*(?:light-dark\(\s*([#\w]+)\s*,\s*([#\w]+)\s*\)|([#\w]+));/m', $css, $matches, \PREG_SET_ORDER);
            self::$roles = [];
            foreach ($matches as $match) {
                // Unmatched groups at the end are left out: a single value has no 2 nor 3.
                $light = ($match[2] ?? '') ?: ($match[4] ?? '');
                self::$roles[$match[1]] = ['light' => $light, 'dark' => ($match[3] ?? '') ?: $light];
            }
        }

        return self::$roles;
    }

    private static function ratio(string $foreground, string $background): float
    {
        $lighter = max(self::luminance($foreground), self::luminance($background));
        $darker = min(self::luminance($foreground), self::luminance($background));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(
            static function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(substr($hex, 1, 6), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}

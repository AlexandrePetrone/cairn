<?php

declare(strict_types=1);

namespace App\Tests\Storia;

use IQ2i\StoriaBundle\Test\PHPUnit\StoriaTestCaseTrait;
use IQ2i\StoriaBundle\Test\PHPUnit\StoriaVariants;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every component variant of the catalogue renders and matches its
 * snapshot in tests/storia/snapshots. Review a diff before accepting it
 * with `bin/console storia:test --update`.
 */
final class StoriaTest extends KernelTestCase
{
    use StoriaTestCaseTrait;

    #[DataProvider('provideVariants')]
    public function testVariantMatchesItsSnapshot(string $path, string $variant): void
    {
        $this->assertVariantMatchesSnapshot($path, $variant);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideVariants(): array
    {
        return StoriaVariants::fromDirectory(\dirname(__DIR__, 2).'/storia');
    }
}

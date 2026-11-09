<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigException;
use App\Config\ConfigLoader;
use App\Tests\Config\Fixture\DefinitionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The files of Fixture/Broken/ are written for another version of the
 * definition objects: the loader must say which line of the file is
 * wrong and why, since that message is all the administrator gets.
 */
final class ConfigLoaderTest extends TestCase
{
    public function testTheFileIsLoaded(): void
    {
        self::assertEquals(DefinitionFactory::definition(), (new ConfigLoader(__DIR__.'/Fixture/instance.php'))->load());
    }

    public function testAMissingFileIsReported(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('not found');
        (new ConfigLoader(__DIR__.'/Fixture/nowhere.php'))->load();
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function brokenFiles(): iterable
    {
        yield 'missing required argument' => ['missing_argument.php', 14, 'Too few arguments to function App\\Config\\Definition\\RoleDefinition::__construct()'];
        yield 'unknown named argument' => ['unknown_parameter.php', 14, 'Unknown named parameter $colour'];
        yield 'invalid definition' => ['duplicate_id.php', 12, 'roles: duplicate identifier "client"'];
    }

    #[DataProvider('brokenFiles')]
    public function testABrokenFileIsReportedWithItsLine(string $file, int $line, string $cause): void
    {
        // Through `..`, as CAIRN_CONFIG may be written: PHP reports the resolved path.
        $path = __DIR__.'/../Config/Fixture/Broken/'.$file;

        try {
            (new ConfigLoader($path))->load();
            self::fail('The file must be refused');
        } catch (ConfigException $e) {
            self::assertStringContainsString(\sprintf('Configuration file "%s" (line %d)', $path, $line), $e->getMessage(), 'the line of the file, not of the constructor');
            self::assertStringContainsString($cause, $e->getMessage());
            self::assertStringContainsString('See UPGRADE.md', $e->getMessage());
        }
    }

    /**
     * Under Symfony's error handler, a warning raised by the file (an array
     * key that an older version provided) is an \ErrorException.
     */
    public function testAWarningRaisedByTheFileIsReportedWithItsLine(): void
    {
        $path = __DIR__.'/Fixture/Broken/undefined_key.php';
        set_error_handler(static fn (int $severity, string $message, string $file, int $line) => throw new \ErrorException($message, 0, $severity, $file, $line));

        try {
            (new ConfigLoader($path))->load();
            self::fail('The file must be refused');
        } catch (ConfigException $e) {
            self::assertStringContainsString(\sprintf('Configuration file "%s" (line 15)', $path), $e->getMessage());
            self::assertStringContainsString('Undefined array key "label"', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }
}

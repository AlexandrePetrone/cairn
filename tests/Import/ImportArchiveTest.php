<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\ImportArchive;
use App\Import\ImportArchiveException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * An archive is read strictly: the example of the format page and the
 * archive of the tests are valid, and one thing missing or misplaced refuses the archive, with its place.
 */
final class ImportArchiveTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/cairn-archive-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testTheExampleOfTheFormatPageIsValid(): void
    {
        // The one place where a test reads the example the page shows: an export script is written from it.
        $displayed = \dirname(__DIR__, 2).'/templates/admin/import/example/';
        $files = ['manifest.json' => (string) file_get_contents($displayed.'manifest.json'), 'issues.ndjson' => (string) file_get_contents($displayed.'issues.ndjson')];
        $archive = new ImportArchive(ArchiveBuilder::write($this->directory, 'displayed.zip', $files), $this->directory.'/work');
        $manifest = $archive->manifest();

        self::assertNotSame([], $manifest->people);
        self::assertNotSame([], iterator_to_array($archive->issues($manifest), false), 'every line follows the manifest');
    }

    public function testTheArchiveOfTheTestsIsValid(): void
    {
        $archive = $this->archive();
        $manifest = $archive->manifest();

        self::assertSame('Redmine', $manifest->source);
        self::assertSame('alice@agency.example', $manifest->people['101']['email']);
        self::assertSame('Alice Martin', $manifest->nameOf('101'));
        self::assertTrue($manifest->workflows['bugs']['statuses']['closed']['closed']);

        $issues = iterator_to_array($archive->issues($manifest), false);
        self::assertSame(['1001', '1002', '1003'], array_map(static fn ($issue) => $issue->id, $issues));
        self::assertSame(['501', '502'], array_column($issues[0]->allFiles(), 'id'));
        self::assertTrue($issues[0]->comments[1]['private']);
        self::assertTrue($issues[1]->closed);
        self::assertSame('2024-03-01T09:00:00+00:00', $issues[0]->createdAt->format(\DATE_ATOM));
        self::assertTrue($archive->hasFile('files/501'));
        self::assertFalse($archive->hasFile('files/999'));
    }

    public function testTheIssuesAreReadFromAPosition(): void
    {
        $archive = $this->archive();
        $manifest = $archive->manifest();
        $positions = array_keys(iterator_to_array($archive->issues($manifest)));

        $rest = iterator_to_array($archive->issues($manifest, $positions[0]), false);
        self::assertSame(['1002', '1003'], array_map(static fn ($issue) => $issue->id, $rest));
        self::assertSame($archive->issuesSize(), $positions[2]);
    }

    /**
     * @param callable(array<string, mixed>, list<array<string, mixed>>, array<string, string>): array{array<string, mixed>, list<array<string, mixed>>, array<string, string>} $change
     */
    #[DataProvider('provideBrokenArchives')]
    public function testOneThingWrongRefusesTheArchive(callable $change, string $expected): void
    {
        $archive = $this->archive($change);

        $this->expectException(ImportArchiveException::class);
        $this->expectExceptionMessage($expected);
        iterator_to_array($archive->issues($archive->manifest()));
    }

    /**
     * @return iterable<string, array{callable, string}>
     */
    public static function provideBrokenArchives(): iterable
    {
        yield 'another format' => [static function (array $manifest, array $issues, array $files): array {
            $manifest['format'] = 2;

            return [$manifest, $issues, $files];
        }, 'Format version "2"'];
        yield 'a person without email' => [static function (array $manifest, array $issues, array $files): array {
            unset($manifest['people'][1]['email']);

            return [$manifest, $issues, $files];
        }, 'people[1].email'];
        yield 'a role nobody declared' => [static function (array $manifest, array $issues, array $files): array {
            $manifest['people'][0]['role'] = 'owner';

            return [$manifest, $issues, $files];
        }, 'people[0].role'];
        yield 'an id with a space' => [static function (array $manifest, array $issues, array $files): array {
            $manifest['trackers'][0]['id'] = 'my bug';

            return [$manifest, $issues, $files];
        }, 'trackers[0].id'];
        yield 'a status of another workflow' => [static function (array $manifest, array $issues, array $files): array {
            $issues[0]['status'] = 'shipped';

            return [$manifest, $issues, $files];
        }, 'issue 1001: status'];
        yield 'an author nobody declared' => [static function (array $manifest, array $issues, array $files): array {
            $issues[1]['author'] = '999';

            return [$manifest, $issues, $files];
        }, 'issue 1002: author'];
        yield 'a file outside files/' => [static function (array $manifest, array $issues, array $files): array {
            $issues[0]['files'][0]['path'] = 'files/../manifest.json';

            return [$manifest, $issues, $files];
        }, 'issue 1001: files[0].path'];
        yield 'a private flag that is not a boolean' => [static function (array $manifest, array $issues, array $files): array {
            $issues[0]['comments'][1]['private'] = 1;

            return [$manifest, $issues, $files];
        }, 'issue 1001: comments[1].private'];
        yield 'a closed flag that is not a boolean' => [static function (array $manifest, array $issues, array $files): array {
            $manifest['workflows'][0]['statuses'][2]['closed'] = 'true';

            return [$manifest, $issues, $files];
        }, 'workflows[0].statuses[2].closed'];
        yield 'two people with one email' => [static function (array $manifest, array $issues, array $files): array {
            $manifest['people'][1]['email'] = 'Alice@agency.example';

            return [$manifest, $issues, $files];
        }, 'two people share the email alice@agency.example'];
        yield 'an issue without an id, named after the one before it' => [static function (array $manifest, array $issues, array $files): array {
            unset($issues[1]['id']);

            return [$manifest, $issues, $files];
        }, 'the line after the issue 1001 is not JSON, or its issue has no id'];
        yield 'a date without offset' => [static function (array $manifest, array $issues, array $files): array {
            $issues[2]['createdAt'] = '2024-03-05 09:00';

            return [$manifest, $issues, $files];
        }, 'issue 1003: createdAt'];
    }

    public function testAZipWithoutManifestOrThatIsNoZipIsRefused(): void
    {
        ArchiveBuilder::write($this->directory, 'empty.zip', ['readme.txt' => 'nothing']);
        try {
            new ImportArchive($this->directory.'/empty.zip', $this->directory.'/work-empty')->manifest();
            self::fail('A zip without manifest was read.');
        } catch (ImportArchiveException $e) {
            self::assertStringContainsString('manifest.json', $e->getMessage());
        }

        file_put_contents($this->directory.'/text.zip', 'not a zip');
        $this->expectException(ImportArchiveException::class);
        new ImportArchive($this->directory.'/text.zip', $this->directory.'/work-text')->manifest();
    }

    private function archive(?callable $change = null): ImportArchive
    {
        return new ImportArchive(ArchiveBuilder::example($this->directory, 'example.zip', $change), $this->directory.'/work');
    }
}

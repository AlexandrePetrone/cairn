<?php

declare(strict_types=1);

namespace App\Tests\Forge;

use App\Forge\IssueKeyParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IssueKeyParserTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('texts')]
    public function testFindsKeys(string $text, array $expected): void
    {
        self::assertSame($expected, (new IssueKeyParser())->keys($text));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function texts(): iterable
    {
        yield 'bare key' => ['Fix the export MD-SPT-12', ['MD-SPT-12']];
        yield 'hash prefix' => ['Closes #MD-SPT-12', ['MD-SPT-12']];
        yield 'branch name' => ['fix/MD-SPT-128-export-csv', ['MD-SPT-128']];
        yield 'lowercase branch' => ['feat/md-spt-7-avoirs', ['MD-SPT-7']];
        yield 'parentheses and colon' => ['(MD-SPT-12): MD-SPT-13.', ['MD-SPT-12', 'MD-SPT-13']];
        yield 'repeated key once' => ['MD-SPT-12 then MD-SPT-12 again', ['MD-SPT-12']];
        yield 'inside a word' => ['exportMD-SPT-12 and MD-SPT-12a', ['MD-SPT-12']];
        yield 'number continues' => ['MD-SPT-1234 is not MD-SPT-123', ['MD-SPT-1234', 'MD-SPT-123']];
        yield 'two keys of two projects' => ["MD-SPT-1\nNC-CAT-3", ['MD-SPT-1', 'NC-CAT-3']];
        yield 'nothing' => ['chore: bump dependencies', []];
    }

    public function testReadsSeveralTextsInOrder(): void
    {
        self::assertSame(['MD-SPT-4', 'MD-SPT-1'], (new IssueKeyParser())->keys('MD-SPT-4 title', '', 'body mentions MD-SPT-1 and MD-SPT-4'));
    }
}

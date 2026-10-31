<?php

declare(strict_types=1);

namespace App\Markdown;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Reads `@[Name](user:12)`, the form the editor writes for a mention.
 */
final class MentionParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('@\[([^\]\n]+)\]\(user:(\d+)\)');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        [$name, $id] = $inlineContext->getSubMatches();
        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new Mention((int) $id, $name));

        return true;
    }
}

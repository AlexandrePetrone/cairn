<?php

declare(strict_types=1);

namespace App\Markdown;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Reads `#` followed by a full issue key. The previous character must not
 * be a word character, so that `abc#MD-SPT-1` stays text.
 */
final class IssueReferenceParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('#([A-Z][A-Z0-9]*-[A-Z][A-Z0-9]*-[0-9]+)\b');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        $previous = $cursor->peek(-1);
        if (null !== $previous && 1 === preg_match('/\w/u', $previous)) {
            return false;
        }

        $cursor->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new IssueReference($inlineContext->getSubMatches()[0]));

        return true;
    }
}

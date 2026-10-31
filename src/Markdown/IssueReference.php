<?php

declare(strict_types=1);

namespace App\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * `#MD-SPT-12` as parsed, before the renderer knows whether the reader may
 * see that issue.
 */
final class IssueReference extends AbstractInline
{
    public function __construct(
        public readonly string $key,
    ) {
        parent::__construct();
    }
}

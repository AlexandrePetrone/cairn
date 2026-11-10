<?php

declare(strict_types=1);

namespace App\Api\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What POST /issues/{key}/links takes: `{"type": "blocks", "issue":
 * "MD-SPT-3"}` makes the issue of the URL block MD-SPT-3; with `inverse`
 * it is blocked by it.
 */
final class LinkInput
{
    /** Id of a link type of the configuration. */
    #[Assert\NotBlank]
    public ?string $type = null;

    /** Key of the other issue. */
    #[Assert\NotBlank(normalizer: 'trim')]
    public ?string $issue = null;

    /** Reads the type from the other issue: "Blocked by" instead of "Blocks". */
    public bool $inverse = false;
}

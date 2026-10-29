<?php

declare(strict_types=1);

namespace App\Controller\Project;

use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * The project of the current URL (`/MD/SPT/…`), as `#[CurrentUser]` gives
 * the signed-in user: `#[CurrentProject] Project $project`.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class CurrentProject extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(CurrentProjectResolver::class);
    }
}

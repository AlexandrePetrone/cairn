<?php

declare(strict_types=1);

namespace App\Form\Admin;

/**
 * What the form confirming a deletion collects: the key of the project
 * or of the workspace, typed again.
 */
final class DeletionData
{
    public ?string $confirmation = null;
}

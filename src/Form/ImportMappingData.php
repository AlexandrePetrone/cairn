<?php

declare(strict_types=1);

namespace App\Form;

use App\Import\ImportMapping;

/**
 * What the configuration pages of an import collect, in the shapes of
 * ImportMapping; each page's form edits its own part, and the controller
 * sends that part as a ConfigureImport.
 */
final class ImportMappingData
{
    public bool $closed = true;

    public bool $files = true;

    public bool $versions = true;

    /** @var array<array-key, array{target: string, name: string}> */
    public array $trackers = [];

    /** @var array<array-key, array<array-key, array<array-key, string>>> */
    public array $places = [];

    /** @var array<array-key, string> */
    public array $fields = [];

    /** @var array<array-key, string> */
    public array $priorities = [];

    /** @var array<array-key, string|null> */
    public array $links = [];

    /** @var array<array-key, array{active?: bool, role: string|null}> */
    public array $people = [];

    public static function of(ImportMapping $mapping): self
    {
        $data = new self();
        foreach (get_object_vars($mapping) as $property => $value) {
            $data->{$property} = $value;
        }

        return $data;
    }
}

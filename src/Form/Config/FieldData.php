<?php

declare(strict_types=1);

namespace App\Form\Config;

use App\Config\FieldType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the dialog of a field edits. The choices of a new field are typed
 * one label per line; afterwards they are a list of their own, on the
 * page of the choices of the field (Config:Choices).
 */
final class FieldData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $label = null;

    #[Assert\NotNull]
    public ?FieldType $type = FieldType::Text;

    public ?string $choices = null;

    #[Assert\Length(max: 255)]
    public ?string $placeholder = null;

    public ?string $help = null;

    public bool $internalOnly = false;

    /**
     * @return list<string> the labels typed, in their order
     */
    public function labels(): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\R/', (string) $this->choices) ?: []), static fn (string $line): bool => '' !== $line));
    }
}

<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use App\Config\FieldType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Declares a field of the instance, of a type it keeps. No workflow asks
 * for it until it is added to one. Handled by CreateFieldHandler, which
 * returns the Field.
 */
final readonly class CreateField implements ConfigurationCommand
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $label,
        public FieldType $type,
        /** @var list<string> the labels of its choices, for a Select or a Choice: each gets its value from its label */
        public array $options = [],
        #[Assert\Length(max: 255)]
        public ?string $placeholder = null,
        public ?string $help = null,
        public bool $internalOnly = false,
    ) {
    }
}

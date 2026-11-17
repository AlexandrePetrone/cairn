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
final readonly class CreateField
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $label,
        public FieldType $type,
        /** @var array<string, string> value => label, for a Select or a Choice */
        public array $options = [],
        #[Assert\Length(max: 255)]
        public ?string $placeholder = null,
        public ?string $help = null,
        public bool $internalOnly = false,
        public ?string $id = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Declares a type of link between two issues; without an inverse label it
 * reads the same from both sides. Handled by CreateLinkTypeHandler, which
 * returns the LinkType.
 */
final readonly class CreateLinkType implements ConfigurationCommand
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $label,
        #[Assert\Length(max: 120)]
        public ?string $inverseLabel = null,
        public ?string $id = null,
    ) {
    }
}

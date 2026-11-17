<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a type of link, PATCH style; an empty inverse label makes it
 * symmetric. Handled by UpdateLinkTypeHandler.
 */
final readonly class UpdateLinkType
{
    public function __construct(
        public string $id,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $label = null,
        #[Assert\Length(max: 120)]
        public ?string $inverseLabel = null,
        #[Assert\PositiveOrZero]
        public ?int $position = null,
    ) {
    }
}

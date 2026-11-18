<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a type of link, PATCH style: its labels and its place in the
 * list. Whether it reads the same from both issues or has a direction was
 * chosen when it was created and stays: an inverse label is given to a
 * type that has one, and never taken away. Handled by
 * UpdateLinkTypeHandler.
 */
final readonly class UpdateLinkType implements ConfigurationCommand
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

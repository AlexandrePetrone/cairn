<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a field, PATCH style: a property left null keeps its value, an
 * empty placeholder or help erases it, `options` replaces the choices (the
 * value an issue holds of a choice that went is then shown as it is
 * stored). Handled by UpdateFieldHandler.
 */
final readonly class UpdateField implements ConfigurationCommand
{
    public function __construct(
        public string $id,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $label = null,
        /** @var list<array{value: ?string, label: string}>|null its choices, in their order: one without a value is new, and gets it from its label */
        public ?array $options = null,
        #[Assert\Length(max: 255)]
        public ?string $placeholder = null,
        public ?string $help = null,
        public ?bool $internalOnly = null,
        #[Assert\PositiveOrZero]
        public ?int $position = null,
    ) {
    }
}

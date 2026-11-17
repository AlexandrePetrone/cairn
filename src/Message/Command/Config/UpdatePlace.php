<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use App\Entity\Enum\PlaceStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a step of the instance, PATCH style. A new status goes to the
 * issues standing on it, in every workflow: entering the closed status
 * closes them, leaving it reopens them. `position` moves it among the
 * others: the order of the filters and groups of the lists. Handled by
 * UpdatePlaceHandler.
 */
final readonly class UpdatePlace implements ConfigurationCommand
{
    public function __construct(
        public string $id,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $label = null,
        public ?PlaceStatus $status = null,
        #[Assert\PositiveOrZero]
        public ?int $position = null,
    ) {
    }
}

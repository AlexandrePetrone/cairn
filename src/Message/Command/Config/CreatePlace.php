<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

use App\Entity\Enum\PlaceStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Declares a step of the instance, after the others. No workflow uses it
 * until it is added to one. Handled by CreatePlaceHandler, which returns
 * the Place.
 */
final readonly class CreatePlace
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $label,
        public PlaceStatus $status,
        public ?string $id = null,
    ) {
    }
}

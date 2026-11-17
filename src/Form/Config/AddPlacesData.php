<?php

declare(strict_types=1);

namespace App\Form\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the dialog that adds steps to a workflow asks: the steps of the
 * instance it does not use yet, as many as wanted at once.
 */
final class AddPlacesData
{
    /** @var list<string> */
    #[Assert\Count(min: 1, minMessage: 'admin.configuration.workflow.places_required')]
    public array $places = [];
}

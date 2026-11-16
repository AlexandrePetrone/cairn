<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What has a place in a list people arrange: a tracker among those of
 * its project, a tracker of a project template among those of its
 * template. MessageHandler\Command\Tracker\ListOrder numbers them.
 */
interface Positioned
{
    public function moveTo(int $position): void;
}

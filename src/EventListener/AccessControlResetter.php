<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Membership;
use App\Security\AccessControl;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * AccessControl remembers the memberships of the users it decided about
 * during the request. A membership written in the meantime (an admin
 * screen, the development story) must be seen by the next decision.
 */
#[AsEntityListener(event: Events::postPersist, entity: Membership::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Membership::class)]
#[AsEntityListener(event: Events::postRemove, entity: Membership::class)]
final class AccessControlResetter
{
    public function __construct(
        private readonly AccessControl $access,
    ) {
    }

    public function __invoke(Membership $membership): void
    {
        $this->access->reset();
    }
}

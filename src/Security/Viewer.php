<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The signed-in user, for the components and Twig extensions that render
 * for them. Every page but the login one requires one, so asking when
 * nobody is signed in is a programming error, not a case to handle.
 */
final class Viewer
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function get(): User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : throw new \LogicException('No signed-in user: this is only rendered on authenticated pages.');
    }
}

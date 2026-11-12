<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The address of the picture someone chose, for the `src` of `Ui:Avatar`:
 * null for nobody and for whoever shows their initials.
 */
final class AvatarExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[AsTwigFunction('avatar_url')]
    public function url(?User $user): ?string
    {
        return null === $user?->getAvatar() ? null : $this->urls->generate('app_avatar', ['name' => $user->getAvatar()]);
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\ConfigChange;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writes what a command did to the configuration, as a sentence in the
 * language of the instance (`admin.configuration.history.<event>`), signed
 * by whoever is signed in. The commands of the configuration carry no
 * actor, since only administrators reach them: the journal asks who is
 * there, and writes nobody for the console and the installation.
 */
final class ConfigJournal
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public function record(string $event, array $parameters = []): void
    {
        $user = $this->security->getUser();

        $this->entityManager->persist(new ConfigChange(
            $this->clock->now(),
            $user instanceof User ? $user->getFullName() : null,
            $this->translator->trans('admin.configuration.history.'.$event, $parameters),
        ));
    }
}

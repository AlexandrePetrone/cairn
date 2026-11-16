<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Template;

use App\Message\Command\Template\DeleteTemplateTracker;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\TemplateRuleRepository;
use App\Repository\TemplateTrackerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Takes the tracker out of its template. The rules limited to it go with
 * it, as with the tracker of a project; the others close ranks.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteTemplateTrackerHandler
{
    public function __construct(
        private readonly TemplateTrackerRepository $trackers,
        private readonly TemplateRuleRepository $rules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteTemplateTracker $command): void
    {
        $tracker = $this->trackers->find($command->trackerId) ?? throw TemplateException::notFound('Template tracker', $command->trackerId);

        foreach ($this->rules->findBy(['tracker' => $tracker]) as $rule) {
            $this->entityManager->remove($rule);
        }
        ListOrder::close($tracker, $this->trackers->findByTemplate($tracker->getTemplate()));
        $this->entityManager->remove($tracker);
    }
}

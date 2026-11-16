<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Template;

use App\Message\Command\Template\UpdateTemplateTracker;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\TemplateTrackerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateTemplateTrackerHandler
{
    public function __construct(
        private readonly TemplateTrackerRepository $trackers,
    ) {
    }

    public function __invoke(UpdateTemplateTracker $command): void
    {
        $tracker = $this->trackers->find($command->trackerId) ?? throw TemplateException::notFound('Template tracker', $command->trackerId);

        if (null !== $command->name) {
            $tracker->rename($command->name);
        }
        if (null !== $command->description) {
            $tracker->setDescription($command->description);
        }
        if (null !== $command->private) {
            $command->private ? $tracker->makePrivate() : $tracker->makePublic();
        }
        if (null !== $command->position) {
            ListOrder::move($tracker, $this->trackers->findByTemplate($tracker->getTemplate()), $command->position);
        }
    }
}

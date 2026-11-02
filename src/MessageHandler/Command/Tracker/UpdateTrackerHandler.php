<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Tracker;

use App\Entity\Tracker;
use App\Message\Command\Tracker\UpdateTracker;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\TrackerRepository;
use App\Security\Permission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateTrackerHandler
{
    public function __construct(
        private readonly TrackerRepository $trackers,
        private readonly ProjectActor $actor,
    ) {
    }

    public function __invoke(UpdateTracker $command): void
    {
        $tracker = $this->trackers->find($command->trackerId) ?? throw TrackerException::notFound('Tracker', $command->trackerId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectConfigure, $tracker->getProject());

        if (null !== $command->name) {
            $tracker->rename($command->name);
        }
        if (null !== $command->description) {
            $tracker->setDescription($command->description);
        }
        if (null !== $command->private) {
            $command->private ? $tracker->makePrivate() : $tracker->makePublic();
        }
        if (null !== $command->active) {
            $command->active ? $tracker->activate() : $tracker->deactivate();
        }
        if (null !== $command->position) {
            $this->move($tracker, $command->position);
        }
    }

    /**
     * Puts the tracker at the position asked, within the bounds of the
     * list, and numbers the whole list again from 0.
     */
    private function move(Tracker $tracker, int $position): void
    {
        $others = array_values(array_filter(
            $this->trackers->findByProject($tracker->getProject(), includePrivate: true, includeInactive: true),
            static fn (Tracker $other): bool => $other !== $tracker,
        ));
        array_splice($others, min($position, \count($others)), 0, [$tracker]);
        foreach ($others as $index => $each) {
            $each->moveTo($index);
        }
    }
}

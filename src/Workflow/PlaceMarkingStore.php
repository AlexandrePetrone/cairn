<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Config\Definition\WorkflowDefinition;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

/**
 * Bridges a state machine and a HasPlace subject: the marking is the
 * subject's place, and moving it hands the subject the full place
 * definition so it can derive status and dates itself.
 */
final class PlaceMarkingStore implements MarkingStoreInterface
{
    public function __construct(
        private readonly WorkflowDefinition $definition,
    ) {
    }

    public function getMarking(object $subject): Marking
    {
        $place = self::subject($subject)->getPlace();

        return new Marking(null === $place ? [] : [$place => 1]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $places = array_keys($marking->getPlaces());
        if (1 !== \count($places)) {
            throw new \LogicException(\sprintf('A %s subject sits on exactly one place, %d given.', HasPlace::class, \count($places)));
        }

        self::subject($subject)->moveTo($this->definition->place((string) $places[0]));
    }

    private static function subject(object $subject): HasPlace
    {
        return $subject instanceof HasPlace
            ? $subject
            : throw new \LogicException(\sprintf('Workflow subjects must implement %s, got %s.', HasPlace::class, get_debug_type($subject)));
    }
}

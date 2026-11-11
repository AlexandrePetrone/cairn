<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\TransitionDefinition;

/**
 * One way a user may take an issue to another place of its workflow: by
 * an action of the workflow, or, without one, outside it
 * (`issue.force_place`). AccessControl::movesFor() lists them.
 */
final readonly class PlaceMove
{
    /**
     * @param TransitionDefinition|null $transition the action that leads to the place; null when the move leaves the workflow
     */
    public function __construct(
        public PlaceDefinition $place,
        public ?TransitionDefinition $transition = null,
    ) {
    }

    public function isForced(): bool
    {
        return null === $this->transition;
    }

    /**
     * The places a list of moves leads to, each once: all of them, or only
     * those reached outside the workflow.
     *
     * @param list<self> $moves
     *
     * @return list<string>
     */
    public static function placeIds(array $moves, bool $forcedOnly = false): array
    {
        $ids = [];
        foreach ($moves as $move) {
            if (!$forcedOnly || $move->isForced()) {
                $ids[$move->place->id] = $move->place->id;
            }
        }

        return array_values($ids);
    }
}

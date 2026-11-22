<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\TransitionDefinition;

/**
 * One way a user may take an issue to another place of its workflow: by
 * an action of theirs, or freely, without one
 * (AccessControl::canMoveFreely()). AccessControl::movesFor() lists them,
 * all of one kind for a given user.
 */
final readonly class PlaceMove
{
    /**
     * @param TransitionDefinition|null $transition the action that leads to the place; null for who moves freely
     */
    public function __construct(
        public PlaceDefinition $place,
        public ?TransitionDefinition $transition = null,
    ) {
    }

    /**
     * The places a list of moves leads to, each once.
     *
     * @param list<self> $moves
     *
     * @return list<string>
     */
    public static function placeIds(array $moves): array
    {
        return array_values(array_unique(array_map(static fn (self $move): string => $move->place->id, $moves)));
    }
}

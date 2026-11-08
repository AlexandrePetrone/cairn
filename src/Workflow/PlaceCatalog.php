<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Config\ConfigRegistry;

/**
 * The places a list offers, workflow by workflow: a list mixing several
 * workflows keeps their places apart ("Bug › In progress", "Task ›
 * In progress"), each named by its key, `workflow:place`, which the SQL
 * builds the same way (Repository/IssueOrder::PLACE_KEY). Workflows come in
 * the order of the instance file, places in the order of their workflow.
 */
final class PlaceCatalog
{
    public function __construct(
        private readonly ConfigRegistry $config,
    ) {
    }

    public static function key(string $workflowId, string $placeId): string
    {
        return $workflowId.':'.$placeId;
    }

    /**
     * @param list<string>|null $workflowIds those of the list, every workflow of the instance when null; unknown ids are skipped
     *
     * @return list<PlaceChoice>
     */
    public function choices(?array $workflowIds = null): array
    {
        $choices = [];
        foreach ($this->config->definition()->workflows as $workflow) {
            if (null !== $workflowIds && !\in_array($workflow->id, $workflowIds, true)) {
                continue;
            }
            foreach ($workflow->places as $place) {
                $choices[] = new PlaceChoice(self::key($workflow->id, $place->id), $place->label, $workflow->label, $place->status);
            }
        }

        return $choices;
    }

    /**
     * The rank of every place key of the instance, from 1, for the SQL that
     * sorts or groups by place.
     *
     * @return array<string, int>
     */
    public function ranks(): array
    {
        $ranks = [];
        foreach ($this->choices() as $index => $choice) {
            $ranks[$choice->key] = $index + 1;
        }

        return $ranks;
    }
}

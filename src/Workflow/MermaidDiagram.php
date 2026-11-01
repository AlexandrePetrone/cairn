<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Config\Definition\WorkflowDefinition;
use App\Entity\Enum\PlaceStatus;

/**
 * Describes a workflow as a Mermaid flowchart, drawn by the browser: a
 * rounded node per place coloured by its status, a start mark on the
 * initial place, an arrow per source of each transition. A transition
 * going back in the kanban order is dotted, so that the main path stands
 * out from the returns. Place ids are configuration ids, valid as Mermaid
 * identifiers; labels are quoted and escaped.
 */
final class MermaidDiagram
{
    /** Fill and stroke of each status, as Ui:Status:Dot colours them. */
    private const array STATUS_STYLES = [
        'open' => 'fill:#eff6ff,stroke:#3b82f6,color:#1e3a8a',
        'in_progress' => 'fill:#fffbeb,stroke:#f59e0b,color:#78350f',
        'waiting' => 'fill:#f5f3ff,stroke:#8b5cf6,color:#4c1d95',
        'closed' => 'fill:#f3f4f6,stroke:#9ca3af,color:#374151',
    ];

    public function render(WorkflowDefinition $workflow): string
    {
        $order = array_flip($workflow->placeIds());
        $lines = ['flowchart LR', '  start(( )):::start --> '.$workflow->initial];

        foreach ($workflow->places as $place) {
            $lines[] = \sprintf('  %s(["%s"]):::%s', $place->id, self::escape($place->label), $place->status->value);
        }
        foreach ($workflow->transitions as $transition) {
            $label = self::escape($transition->label.($transition->requiresComment ? ' 💬' : ''));
            foreach ($transition->from as $from) {
                $arrow = $order[$transition->to] < $order[$from] ? '-.->' : '-->';
                $lines[] = \sprintf('  %s %s|"%s"| %s', $from, $arrow, $label, $transition->to);
            }
        }

        foreach (PlaceStatus::cases() as $status) {
            $lines[] = \sprintf('  classDef %s %s', $status->value, self::STATUS_STYLES[$status->value]);
        }
        $lines[] = '  classDef start fill:#111827,stroke:#111827';

        return implode("\n", $lines)."\n";
    }

    /**
     * Mermaid ends a quoted label at `"` and reads `#` as the start of an
     * entity: both are written as entities, as Symfony's MermaidDumper does.
     */
    private static function escape(string $label): string
    {
        return str_replace(['#', '"'], ['#35;', '#quot;'], $label);
    }
}

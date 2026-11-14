<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Config\Definition\WorkflowDefinition;
use App\Entity\Enum\PlaceStatus;

/**
 * Describes a workflow as a Mermaid flowchart, drawn by the browser from
 * top to bottom: a box per place coloured by its status, a start mark on the
 * initial place, an arrow per source of each transition. A transition
 * going back in the kanban order is dotted, so that the main path stands
 * out from the returns. Place ids are configuration ids, valid as Mermaid
 * identifiers; labels are quoted and escaped.
 */
final class MermaidDiagram
{
    /**
     * Fill and stroke of each status, as Ui:Status:Dot colours them. The
     * colours are the roles of app.css, which the `workflow-diagram`
     * controller resolves when drawing, so that the drawing follows the theme.
     */
    private const array STATUS_STYLES = [
        'open' => 'fill:var(--color-blue-soft),stroke:var(--color-blue-solid),color:var(--color-blue)',
        'in_progress' => 'fill:var(--color-amber-soft),stroke:var(--color-amber-solid),color:var(--color-amber)',
        'waiting' => 'fill:var(--color-violet-soft),stroke:var(--color-violet-solid),color:var(--color-violet)',
        'closed' => 'fill:var(--color-sunken),stroke:var(--color-faint),color:var(--color-body)',
    ];

    public function render(WorkflowDefinition $workflow): string
    {
        $order = array_flip($workflow->placeIds());
        $lines = ['flowchart TB', '  start(( )):::start --> '.$workflow->initial];

        foreach ($workflow->places as $place) {
            $lines[] = \sprintf('  %s("%s"):::%s', $place->id, self::escape($place->label), $place->status->value);
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
        $lines[] = '  classDef start fill:var(--color-strong),stroke:var(--color-strong)';

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

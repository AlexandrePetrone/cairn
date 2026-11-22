<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Enum\PlaceStatus;

/**
 * Describes a workflow as a Mermaid flowchart, drawn by the browser from
 * top to bottom, for whoever it is explained to: a box per place coloured
 * by its status, and the path its issues follow, its places linked in
 * their order without those the workflow sets aside
 * (WorkflowDefinition::path()), each of which hangs by a dotted line from
 * the place of the path it stands beside. Nobody is held to that path: the
 * roles that move freely go anywhere. What the others may do is drawn over it:
 * an arrow per source of each action a role triggers, named after it and
 * those roles, the place it starts from outlined; where an action leads to
 * the next place of the path, the arrow of the path is the action's. An
 * action going back in the kanban order is dotted. On the page of an issue
 * the place it stands on is outlined in the colour of the brand. Place ids are
 * configuration ids, valid as Mermaid identifiers; labels are quoted and
 * escaped.
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

    /** What tells a place an action starts from, and the arrow of an action, from the path. */
    private const string ACTED = 'stroke:var(--color-strong),stroke-width:2.5px';
    private const string ACTION = 'stroke:var(--color-strong),stroke-width:2px';

    /** The outline of the place an issue stands on, when the diagram is drawn for one. */
    private const string CURRENT = 'stroke:var(--color-brand-solid),stroke-width:4px';

    /**
     * @param string|null $current the place of the issue the diagram is drawn for
     */
    public function render(InstanceDefinition $definition, WorkflowDefinition $workflow, ?string $current = null): string
    {
        $order = array_flip($workflow->places);

        // from => to => the action; the configuration allows one per pair of places. An action no role
        // triggers is nobody's to take: it is not drawn.
        $actions = [];
        foreach ($workflow->transitions as $transition) {
            if ([] === $transition->roles) {
                continue;
            }
            foreach ($transition->from as $from) {
                $actions[$from][$transition->to] = $transition;
            }
        }

        $lines = ['flowchart TB', '  start(( )):::start --> '.self::node($workflow->initial)];
        foreach ($definition->placesOf($workflow) as $place) {
            $lines[] = \sprintf('  %s("%s"):::%s%s', self::node($place->id), self::escape($place->label), $place->status->value, isset($actions[$place->id]) ? '_acted' : '');
        }

        // Links are styled by their rank in the source; the one from the start mark is the first.
        $drawn = 1;
        $strong = [];
        $onThePath = [];
        $path = $workflow->path();
        foreach ($path as $step => $from) {
            $to = $path[$step + 1] ?? null;
            if (null === $to) {
                break;
            }
            $action = $actions[$from][$to] ?? null;
            if (null !== $action) {
                $strong[] = $drawn;
                $onThePath[$from][$to] = true;
            }
            $lines[] = \sprintf('  %s -->%s %s', self::node($from), null === $action ? '' : self::label($definition, $action), self::node($to));
            ++$drawn;
        }
        foreach ($actions as $from => $targets) {
            foreach ($targets as $to => $action) {
                if (isset($onThePath[$from][$to])) {
                    continue;
                }
                $lines[] = \sprintf('  %s %s%s %s', self::node($from), $order[$to] < $order[$from] ? '-.->' : '-->', self::label($definition, $action), self::node($to));
                $strong[] = $drawn++;
            }
        }
        // A place beside the path hangs from the place it stands beside, unless an action already leads
        // there from it: someone who moves freely puts the issue there, and back when no action takes it out.
        foreach ($workflow->aside as $aside => $beside) {
            if (!isset($actions[$beside][$aside])) {
                $lines[] = \sprintf('  %s %s %s', self::node($beside), isset($actions[$aside]) ? '-.->' : '<-.->', self::node($aside));
            }
        }

        foreach (PlaceStatus::cases() as $status) {
            $lines[] = \sprintf('  classDef %s %s', $status->value, self::STATUS_STYLES[$status->value]);
            $lines[] = \sprintf('  classDef %s_acted %s,%s', $status->value, self::STATUS_STYLES[$status->value], self::ACTED);
        }
        $lines[] = '  classDef start fill:var(--color-strong),stroke:var(--color-strong)';
        if (null !== $current && $workflow->hasPlace($current)) {
            $lines[] = \sprintf('  style %s %s', self::node($current), self::CURRENT);
        }
        if ([] !== $strong) {
            $lines[] = \sprintf('  linkStyle %s %s', implode(',', $strong), self::ACTION);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * What an arrow says of its action: its name, the comment it asks
     * for, and under it the roles that trigger it.
     */
    private static function label(InstanceDefinition $definition, TransitionDefinition $action): string
    {
        $label = self::escape($action->label.($action->requiresComment ? ' 💬' : ''));
        $roles = implode(', ', array_map(static fn (string $roleId): string => self::escape($definition->role($roleId)->label), $action->roles));

        return \sprintf('|"%s%s"|', $label, "\n".$roles);
    }

    /**
     * The node of a place. Its key alone could be a word Mermaid reads
     * (`end`, `class`, `style`…) or the `start` node of the diagram: the
     * key of a step is made from its label, so any word may come.
     */
    private static function node(string $placeId): string
    {
        return 'p_'.$placeId;
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

<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Visual weight of a transition button on the issue page and the kanban.
 */
enum TransitionStyle: string
{
    case Default = 'default';
    case Primary = 'primary';
    case Danger = 'danger';
}

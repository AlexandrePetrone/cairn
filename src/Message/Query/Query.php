<?php

declare(strict_types=1);

namespace App\Message\Query;

/**
 * A screen read dispatched on the `query.bus`. The type parameter is what
 * its handler returns, so that QueryBus::ask() is typed at the call site.
 *
 * @template-covariant TResult
 */
interface Query
{
}

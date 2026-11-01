<?php

declare(strict_types=1);

namespace App\Message\Query\Config;

use App\Message\Query\Query;

/**
 * How many memberships carry each role id, including roles the
 * configuration no longer declares.
 *
 * @implements Query<array<string, int>>
 */
final readonly class CountMembershipsByRole implements Query
{
}

<?php

declare(strict_types=1);

namespace App\Forge;

/**
 * The forge refused a call or could not be reached. The message is for the
 * logs; the command handlers turn it into a translatable refusal.
 */
final class ForgeApiException extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace App\Message\Command\Config;

/**
 * A command that changes the configuration of the instance. They run one
 * at a time, whoever sends them (MessageHandler\Command\Config\ConfigurationLock):
 * each checks what it is asked against the configuration as it reads it,
 * so two of them must not read it at the same moment.
 */
interface ConfigurationCommand
{
}

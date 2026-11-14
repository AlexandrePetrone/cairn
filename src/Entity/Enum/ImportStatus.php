<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Where an import stands: its archive is analysed, then an administrator
 * configures it, then it runs. A failed import resumes where it stopped;
 * a completed or abandoned one is over.
 */
enum ImportStatus: string
{
    case Analysing = 'analysing';
    case Configuring = 'configuring';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    /** Given up before the end: what it wrote stays, its archive may be imported again. */
    case Abandoned = 'abandoned';

    /** A worker is on it. */
    public function isInProgress(): bool
    {
        return self::Analysing === $this || self::Running === $this;
    }

    /** Over: it neither runs nor resumes. */
    public function isFinished(): bool
    {
        return self::Completed === $this || self::Abandoned === $this;
    }
}

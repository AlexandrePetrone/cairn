<?php

declare(strict_types=1);

namespace App\Message\Query\Release;

use App\Entity\Enum\PlaceStatus;
use App\Entity\Release;

/**
 * A release and the issues of it the viewer sees, counted by status.
 */
final readonly class ReleaseSummary
{
    /**
     * @param array<string, int> $byStatus status value => issues, every status present
     */
    public function __construct(
        public Release $release,
        public array $byStatus,
    ) {
    }

    public function total(): int
    {
        return array_sum($this->byStatus);
    }

    /** Share of the issues that are closed, from 0 to 100. */
    public function progress(): int
    {
        return 0 === $this->total() ? 0 : (int) round($this->byStatus[PlaceStatus::Closed->value] / $this->total() * 100);
    }

    /**
     * The counts as Ui:StackedBar takes them, closed first.
     *
     * @return list<array{status: string, value: int}>
     */
    public function segments(): array
    {
        $segments = [];
        foreach ([PlaceStatus::Closed, PlaceStatus::Waiting, PlaceStatus::InProgress, PlaceStatus::Open] as $status) {
            $segments[] = ['status' => $status->value, 'value' => $this->byStatus[$status->value]];
        }

        return $segments;
    }
}

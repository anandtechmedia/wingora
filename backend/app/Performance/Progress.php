<?php

namespace App\Performance;

use App\Models\PerformanceKeyResult;
use App\Models\PerformanceObjective;

/** Progress is 0-100. A key result's is how far it has moved from start toward target; an OKR's is their weighted mean. */
final class Progress
{
    public static function keyResult(PerformanceKeyResult $kr): int
    {
        $start = (float) $kr->start_value;
        $target = (float) $kr->target_value;
        $current = (float) $kr->current_value;
        if ($target === $start) {
            return $current >= $target ? 100 : 0;
        }

        return (int) round(max(0, min(1, ($current - $start) / ($target - $start))) * 100);
    }

    public static function objective(PerformanceObjective $objective): int
    {
        $results = $objective->keyResults()->get();
        $weight = $results->sum('weight');
        if ($results->isEmpty() || $weight === 0) {
            return (int) $objective->progress;
        }

        return (int) round($results->sum(fn ($kr) => self::keyResult($kr) * $kr->weight) / $weight);
    }
}

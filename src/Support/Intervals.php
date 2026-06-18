<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Support;

/**
 * Interval-union (sweep-line) helper used to compute per-category "self time".
 *
 * Why union and not a plain sum: child spans can overlap (parallel HTTP calls,
 * async queries, nested instrumentation). Summing their durations double-counts
 * the wall-clock time. The union of intervals gives the real wall-clock time
 * actually spent inside that category, which is what the dashboard breakdown and
 * the ingestion service's self-time rollups expect.
 *
 * We work in plain integer nanoseconds. PHP ints are 64-bit on any supported
 * platform, and durations within a single request comfortably fit.
 */
final class Intervals
{
    /**
     * Total wall-clock length covered by the union of [start, end] intervals.
     *
     * @param list<array{0:int,1:int}> $intervals pairs of [startNs, endNs]
     */
    public static function unionLength(array $intervals): int
    {
        if ($intervals === []) {
            return 0;
        }

        // Sort by start so a single forward sweep can merge overlaps.
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $total = 0;
        [$curStart, $curEnd] = $intervals[0];

        $count = count($intervals);
        for ($i = 1; $i < $count; $i++) {
            [$s, $e] = $intervals[$i];
            if ($s > $curEnd) {
                // Disjoint: bank the current run and start a new one.
                $total += $curEnd - $curStart;
                $curStart = $s;
                $curEnd = $e;
            } elseif ($e > $curEnd) {
                // Overlapping: extend the current run.
                $curEnd = $e;
            }
        }

        $total += $curEnd - $curStart;

        return $total;
    }
}

<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Restlytics\Laravel\Support\Intervals;

final class IntervalsTest extends TestCase
{
    public function test_empty_is_zero(): void
    {
        $this->assertSame(0, Intervals::unionLength([]));
    }

    public function test_single_interval(): void
    {
        $this->assertSame(10, Intervals::unionLength([[0, 10]]));
    }

    public function test_disjoint_intervals_sum(): void
    {
        // [0,10] + [20,25] = 10 + 5
        $this->assertSame(15, Intervals::unionLength([[0, 10], [20, 25]]));
    }

    public function test_overlapping_intervals_are_unioned_not_summed(): void
    {
        // [0,10] and [5,15] overlap → union is [0,15] = 15 (NOT 10+10=20).
        $this->assertSame(15, Intervals::unionLength([[0, 10], [5, 15]]));
    }

    public function test_fully_contained_interval(): void
    {
        // [2,4] inside [0,10] → just 10.
        $this->assertSame(10, Intervals::unionLength([[0, 10], [2, 4]]));
    }

    public function test_adjacent_touching_intervals_merge(): void
    {
        // [0,10] and [10,20] touch at 10 → continuous [0,20] = 20.
        $this->assertSame(20, Intervals::unionLength([[0, 10], [10, 20]]));
    }

    public function test_unsorted_input_is_handled(): void
    {
        $this->assertSame(15, Intervals::unionLength([[20, 25], [0, 10]]));
    }

    public function test_multiple_overlaps_chained(): void
    {
        // [0,5],[3,8],[7,12] all chain → [0,12] = 12.
        $this->assertSame(12, Intervals::unionLength([[0, 5], [3, 8], [7, 12]]));
    }

    public function test_zero_length_intervals(): void
    {
        // Cache markers are zero-length; they contribute nothing on their own.
        $this->assertSame(0, Intervals::unionLength([[5, 5], [10, 10]]));
    }
}

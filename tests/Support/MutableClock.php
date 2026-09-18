<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests\Support;

/**
 * Time the test moves by hand.
 *
 * `flush_interval_ms` is two seconds by default. A test that proved the interval by sleeping would add
 * two seconds to every run and would still only prove it on a machine that was not busy.
 */
final class MutableClock
{
    private float $now;

    /**
     * Starts at the real clock on purpose: PeriodicFlush hands the Sender an ABSOLUTE deadline on this
     * same clock, and a fake epoch would put every deadline decades in the past — a flush that silently
     * sends nothing, and a test that passes for the wrong reason.
     */
    public function __construct(?float $now = null)
    {
        $this->now = $now ?? microtime(true);
    }

    public function __invoke(): float
    {
        return $this->now;
    }

    public function advanceMs(int $milliseconds): void
    {
        $this->now += $milliseconds / 1000;
    }
}

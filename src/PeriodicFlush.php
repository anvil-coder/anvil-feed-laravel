<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel;

use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\Logger;
use Illuminate\Contracts\Container\Container;

/**
 * When the events leave a process that has no response to terminate after.
 *
 * A web request flushes once, after the response. A `queue:work` daemon and a long-running Artisan
 * command do not: they live for hours, and until this class existed their events sat in memory until the
 * process died — at `queue:restart`, at `--max-time`, or never. A worker that fails often would reach the
 * 1000-event bound and start dropping the OLDEST events, which is the first occurrence of an incident:
 * exactly the one the cockpit shows as "first seen".
 *
 * `flush_interval_ms` (default 2000) is what this reads. It was a published, documented knob that nothing
 * in the SDK consumed; it is now the cadence of every long-lived process.
 *
 * The rule: due on `Looping` (every worker loop iteration, idle ones included), on `JobProcessed` and
 * `JobFailed`, and on `CommandFinished`; unconditional on `WorkerStopping` and after a response.
 */
final class PeriodicFlush
{
    private float $lastFlushAt;

    /** @var \Closure(): float */
    private \Closure $clock;

    private bool $failureSaid = false;

    public function __construct(private readonly Container $container, ?callable $clock = null)
    {
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn (): float => microtime(true);
        $this->lastFlushAt = ($this->clock)();
    }

    /** Flushes if `flush_interval_ms` has elapsed since the last one. Never throws. */
    public function whenDue(): void
    {
        try {
            $intervalMs = $this->container->make(Config::class)->flushIntervalMs;
            if ((($this->clock)() - $this->lastFlushAt) * 1000 < $intervalMs) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $this->now();
    }

    /**
     * Flushes whatever is queued, bounded by `request_timeout_ms`, whether or not the interval has
     * elapsed. Whatever the deadline cuts short stays in the queue for the next flush rather than holding
     * the process.
     */
    public function now(): void
    {
        $this->lastFlushAt = ($this->clock)();

        try {
            $budgetMs = $this->container->make(Config::class)->requestTimeoutMs;
            $this->container->make(Client::class)->flush(($this->clock)() + ($budgetMs / 1000));
        } catch (\Throwable $ours) {
            // Client::flush() logs its own failures and Sender logs the transport's, so what reaches here
            // is the case nobody has a diagnosis for — a container torn down early, a Config binding that
            // raises. Silence would make that a permanent, invisible no-op: everything queues and nothing
            // ever leaves. One line, once, and never an exception out of this method (FR-2 b).
            $this->sayOnce($ours);
        }
    }

    /** The last time a flush was attempted, on this instance's clock. For tests and for diagnostics. */
    public function lastFlushAt(): float
    {
        return $this->lastFlushAt;
    }

    private function sayOnce(\Throwable $ours): void
    {
        if ($this->failureSaid) {
            return;
        }
        $this->failureSaid = true;

        try {
            $this->container->make(Logger::class)->once(
                'flush-after-response',
                'anvil-feed: the flush after the response could not run: ' . $ours->getMessage()
                    . '. Events are queueing and nothing is leaving this process. This is said once.'
            );
        } catch (\Throwable) {
            // There is nowhere left to report to.
        }
    }
}

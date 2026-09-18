<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests;

use AnvilFeed\Laravel\PeriodicFlush;
use AnvilFeed\Laravel\Tests\Support\FakeJob;
use AnvilFeed\Laravel\Tests\Support\MutableClock;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * How an event leaves a process that never terminates a response.
 *
 * `php artisan queue:work` runs for hours. Until these listeners existed, a job that failed at 09:00 sat
 * in memory until `queue:restart`, `--max-time` or a deploy — and on a worker that fails often the 1000
 * event bound drops the OLDEST first, which is the first occurrence of the incident.
 *
 * Every test here asserts the event reached the transport WITHOUT the test calling flush(): a test that
 * flushes by hand proves the queue works, not that anything drains it.
 */
final class WorkerFlushTest extends TestCase
{
    private MutableClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new MutableClock();
        $this->app->instance(PeriodicFlush::class, new PeriodicFlush($this->app, $this->clock));
    }

    public function testAFailedJobLeavesTheWorkerOneLoopIterationAfterTheIntervalWithoutTheProcessExiting(): void
    {
        $this->dispatch(new JobFailed('redis', new FakeJob(), new \RuntimeException('the job broke')));

        self::assertSame(1, $this->client()->queue()->count(), 'the failure is an event');
        self::assertSame([], $this->transport->calls, 'and nothing is sent before the interval elapses');

        // One loop iteration before the interval is up: still nothing.
        $this->clock->advanceMs(1999);
        self::assertNull(
            $this->app->make('events')->until(new Looping('redis', 'queue-worker')),
            'a listener that answers Looping would pause the worker'
        );
        self::assertSame([], $this->transport->calls);

        // The next iteration after flush_interval_ms has elapsed.
        $this->clock->advanceMs(2);
        $this->app->make('events')->until(new Looping('redis', 'queue-worker'));

        self::assertCount(1, $this->transport->calls, 'the event left the process while it kept running');
        self::assertSame(0, $this->client()->queue()->count());
        self::assertStringContainsString('the job broke', $this->transport->calls[0]['body']);
    }

    public function testTheIntervalIsTheConfiguredOne(): void
    {
        $this->app['config']->set('anvil-feed.flush_interval_ms', 500);
        $this->rebuildSdk();
        $this->app->instance(PeriodicFlush::class, new PeriodicFlush($this->app, $this->clock));

        $this->client()->captureMessage('a long-running import said something');
        $this->clock->advanceMs(501);
        $this->dispatch(new JobProcessed('redis', new FakeJob()));

        self::assertCount(1, $this->transport->calls);
    }

    public function testAWorkerThatIsStoppingFlushesWhateverIsLeftWhateverTheInterval(): void
    {
        $this->dispatch(new JobFailed('redis', new FakeJob(), new \RuntimeException('the job broke')));
        self::assertSame([], $this->transport->calls);

        $this->dispatch(new WorkerStopping(0, new WorkerOptions()));

        self::assertCount(1, $this->transport->calls, 'a worker shutting down does not wait for the interval');
        self::assertSame(0, $this->client()->queue()->count());
    }

    public function testALongRunningArtisanCommandFlushesOnTheSameInterval(): void
    {
        $this->client()->captureMessage('the importer hit a bad row');
        self::assertSame([], $this->transport->calls);

        $this->clock->advanceMs(2001);
        $this->dispatch(new CommandFinished('import:rows', new ArrayInput([]), new NullOutput(), 0));

        self::assertCount(1, $this->transport->calls);
    }

    public function testTheFlushIsBoundedByRequestTimeoutMsHereAsWell(): void
    {
        $this->app['config']->set('anvil-feed.request_timeout_ms', 400);
        // This used to configure an interval of ZERO, so that every Looping iteration was due and the
        // deadline needed no clock movement — the deadline PeriodicFlush hands the Sender is ABSOLUTE and
        // is compared against the real clock, so a fake clock run forward hands the socket the distance
        // between the two clocks and the budget read back is that distance too large.
        //
        // Zero stopped being available: `flush_interval_ms` is floored at 1 in BOTH languages since the
        // parity round, because an interval of zero is a busy loop rather than a faster flush. The floor
        // is the decision, not the accident — so the test keeps its property (fake time == real time at
        // the moment of the flush) a different way: the fake clock starts ten milliseconds BEHIND the real
        // one and is advanced by exactly those ten. The interval has then elapsed, and the deadline is
        // still the real clock's.
        //
        // Ten and not one: a unix timestamp is around 1.7e9 and a double's step at that magnitude is
        // ~2.4e-7, so advancing by 0.001 s and multiplying by 1000 can land at 0.99976 — under the floored
        // interval, and the flush silently does not happen. The gap has to be wide enough that the
        // arithmetic is not the thing being measured.
        $this->app['config']->set('anvil-feed.flush_interval_ms', 1);
        $this->rebuildSdk();
        $this->clock = new MutableClock(microtime(true) - 0.010);
        $this->app->instance(PeriodicFlush::class, new PeriodicFlush($this->app, $this->clock));

        $this->client()->captureMessage('bounded in a worker too');
        $this->clock->advanceMs(10);
        $this->dispatch(new Looping('redis', 'queue-worker'));

        self::assertCount(1, $this->transport->calls, 'the floored interval has elapsed, so the loop is due');
        self::assertLessThanOrEqual(400, (int) $this->transport->budgets()[0]);
    }

    private function dispatch(object $event): void
    {
        $this->app->make('events')->dispatch($event);
    }
}

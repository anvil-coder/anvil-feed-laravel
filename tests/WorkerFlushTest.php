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
        // Interval zero rather than an advanced clock: the deadline the Sender gets is absolute, so a
        // clock the test has run forward would hand the socket the distance between the two clocks.
        $this->app['config']->set('anvil-feed.flush_interval_ms', 0);
        $this->rebuildSdk();

        $this->client()->captureMessage('bounded in a worker too');
        $this->dispatch(new Looping('redis', 'queue-worker'));

        self::assertCount(1, $this->transport->calls);
        self::assertLessThanOrEqual(400, (int) $this->transport->budgets()[0]);
    }

    private function dispatch(object $event): void
    {
        $this->app->make('events')->dispatch($event);
    }
}

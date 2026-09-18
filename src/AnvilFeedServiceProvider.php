<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel;

use AnvilFeed\AnvilFeed;
use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\GitContext;
use AnvilFeed\Logger;
use AnvilFeed\Transport\DirectTransport;
use AnvilFeed\Transport\PsrTransport;
use AnvilFeed\Transport\Transport;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The whole integration: five bindings, one Artisan command, the listeners, and the callbacks that flush
 * once the work is done. FR-1 c — the diff an application takes on is init and configuration, nothing else.
 *
 * Z4, stated as what is actually true and asserted: **no socket is opened inside the request path**. The
 * request path enqueues; every send rides terminating(), the worker's own loop, or the end of the process,
 * and each one is bounded by request_timeout_ms. Enqueueing is not free — building an event reads the
 * source of the frames in the stack — and it is deliberately the only thing the request pays for. Whether
 * the terminating flush is observable by the client depends on the SAPI: php-fpm has already sent the
 * response (fastcgi_finish_request); a SAPI without it spends the budget before the connection closes.
 *
 * This provider is deliberately NOT deferrable: AnvilFeed::init() has to be live before any application
 * code calls the facade, and a deferred provider is one an application only discovers it needed after the
 * exception it wanted reported.
 */
final class AnvilFeedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/anvil-feed.php', 'anvil-feed');

        // ONE Logger for the whole SDK. Logger::once() is per instance, so a second instance means every
        // "said once" line is said twice — and the symptom is a repeated paragraph in a log nobody files
        // a ticket about.
        $this->app->singleton(Logger::class, static function (Application $app): Logger {
            $channel = $app->bound('log') ? $app->make('log') : null;

            return new Logger($channel instanceof LoggerInterface ? $channel : null);
        });

        $this->app->singleton(Config::class, static function (Application $app): Config {
            $options = $app['config']->get('anvil-feed');
            $options = is_array($options) ? $options : [];

            // What the application actually runs as, not what its .env said. config('app.name') and
            // config('app.env') are the values every other package reads, and a test or a config file
            // that sets them must not leave this SDK reporting under a different name.
            $options['service'] = self::firstNonEmpty($options['service'] ?? null, $app['config']->get('app.name'));
            $options['environment'] = self::firstNonEmpty(
                $options['environment'] ?? null,
                $app['config']->get('app.env')
            );

            // Total by contract: an option this cannot read becomes the documented default plus one log
            // line. A mistake in config/anvil-feed.php is visible, never a failure to boot.
            return Config::fromArray($options, $app->make(Logger::class));
        });

        $this->app->singleton(VerdictTransport::class, function (Application $app): VerdictTransport {
            return new VerdictTransport($this->chooseTransport($app));
        });

        $this->app->singleton(PeriodicFlush::class, static function (Application $app): PeriodicFlush {
            return new PeriodicFlush($app);
        });

        $this->app->singleton(Client::class, static function (Application $app): Client {
            return Client::create(
                $app->make(Config::class),
                $app->make(VerdictTransport::class),
                $app->make(Logger::class),
                GitContext::discover($app->basePath())
            );
        });
    }

    public function boot(): void
    {
        $this->publishes(
            [__DIR__ . '/../config/anvil-feed.php' => $this->app->configPath('anvil-feed.php')],
            'anvil-feed-config'
        );

        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class]);
        }

        $client = $this->app->make(Client::class);
        AnvilFeed::init($client);

        // After the response is on the wire (Z4). Application::terminate() calls its callbacks through
        // the container, so the typed parameter is the container that is terminating — under Octane's
        // per-request sandbox that is not necessarily the one this provider was constructed with, and the
        // events that need flushing are in the one being torn down.
        $this->app->terminating(function (Application $app): void {
            $this->flushAfterResponse($app);
        });

        // The last resort for a process that ends without any of the events below firing.
        // Client::registerShutdownFlush() is register_shutdown_function() and is idempotent; calling it
        // here means the hook exists from boot rather than from the first captured event, and that there
        // is exactly one hook rather than one of ours plus one of the core's.
        if ($this->app->runningInConsole()) {
            $client->registerShutdownFlush();
        }

        $events = $this->app->make('events');

        // The queue's own failure path. Laravel fires JobFailed from Worker::handleJobException() BEFORE
        // Worker::runJob() hands the same throwable to the exception handler, so this listener is the one
        // that gets to report a failed job — which is why the `queue` tag is on the event and not lost to
        // whichever path happened to run first. Reported::firstTime() is what makes "exactly one event"
        // (FR-1 a) true across the two paths.
        $events->listen(JobFailed::class, JobFailureListener::class);

        // …and this is how that event LEAVES a worker that runs for hours. Registered after the listener
        // above, because Laravel fires listeners in registration order and a flush before the capture
        // would post an empty queue. `Looping` fires on every iteration of the worker loop, idle ones
        // included, so an event queued by a failing job is never more than flush_interval_ms plus one
        // iteration away from the ingest. None of these callbacks may return a value: Worker dispatches
        // Looping through `until()`, and a non-null answer there pauses the worker.
        $events->listen(Looping::class, function (): void {
            $this->app->make(PeriodicFlush::class)->whenDue();
        });
        $events->listen(JobProcessed::class, function (): void {
            $this->app->make(PeriodicFlush::class)->whenDue();
        });
        $events->listen(JobFailed::class, function (): void {
            $this->app->make(PeriodicFlush::class)->whenDue();
        });
        $events->listen(WorkerStopping::class, function (): void {
            $this->app->make(PeriodicFlush::class)->now();
        });

        // The same cadence for a long-running Artisan command — a scheduler loop, an importer, a
        // `horizon` supervisor. A command that ends releases the rest through the shutdown hook above.
        $events->listen(CommandFinished::class, function (): void {
            $this->app->make(PeriodicFlush::class)->whenDue();
        });
    }

    /**
     * The flush that rides the end of a request. The budget is at most request_timeout_ms of wall clock
     * AFTER the response, for the whole drain; whatever the deadline cuts short stays in the queue for the
     * next flush rather than holding the worker. Nothing in here can reach the application — including a
     * transport that throws in breach of its own contract, and including a container torn down early,
     * which PeriodicFlush says once rather than swallowing.
     */
    public function flushAfterResponse(?Container $container = null): void
    {
        try {
            ($container ?? $this->app)->make(PeriodicFlush::class)->now();
        } catch (\Throwable) {
            // The container itself could not hand out the flusher. There is nothing left to log through.
        }
    }

    /**
     * An explicitly bound Transport always wins — that is the documented escape hatch, and it is what a
     * test suite binds. Otherwise config('anvil-feed.transport') decides, and 'psr' without a PSR-18
     * client in the container falls back rather than failing to boot.
     */
    private function chooseTransport(Application $app): Transport
    {
        $config = $app->make(Config::class);

        if ($app->bound(Transport::class)) {
            return $app->make(Transport::class);
        }

        $choice = $app['config']->get('anvil-feed.transport');
        if (!is_string($choice) || strtolower(trim($choice)) !== 'psr') {
            return new DirectTransport($config);
        }

        if ($app->bound(ClientInterface::class)
            && $app->bound(RequestFactoryInterface::class)
            && $app->bound(StreamFactoryInterface::class)) {
            return new PsrTransport(
                $config,
                $app->make(ClientInterface::class),
                $app->make(RequestFactoryInterface::class),
                $app->make(StreamFactoryInterface::class)
            );
        }

        $app->make(Logger::class)->once('transport', 'anvil-feed: transport is configured as "psr" but no '
            . 'PSR-18 client is bound in this container; the bundled transport is used instead.');

        return new DirectTransport($config);
    }

    private static function firstNonEmpty(mixed $configured, mixed $fallback): mixed
    {
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return is_string($fallback) && trim($fallback) !== '' ? $fallback : $configured;
    }

}

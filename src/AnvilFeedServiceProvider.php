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
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The whole integration: four bindings, one Artisan command, one listener and one callback that runs
 * after the response. FR-1 c — the diff an application takes on is init and configuration, nothing else.
 *
 * Z4 is why the flush rides terminating() and the shutdown hook: no socket is opened inside the request
 * path, so an ingest that is slow, rate limiting or simply gone cannot add a millisecond to a customer's
 * p99. What it can do is hold a worker for at most request_timeout_ms AFTER the response is on the wire.
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

        // After the response is on the wire (Z4). Resolved from the container at call time so that a host
        // — or a test — that rebinds the client still gets the one that holds the queued events.
        $this->app->terminating(function (): void {
            $this->flushAfterResponse();
        });

        // A queue worker and an Artisan command have no response to terminate after, so the end of the
        // process is the flush point. Client::registerShutdownFlush() is register_shutdown_function() and
        // is idempotent; calling it here means the hook exists from boot rather than from the first
        // captured event, and it means exactly one hook rather than one of ours plus one of the core's.
        if ($this->app->runningInConsole()) {
            $client->registerShutdownFlush();
        }

        // The queue's own failure path. Laravel fires JobFailed from Worker::handleJobException() BEFORE
        // Worker::runJob() hands the same throwable to the exception handler, so this listener is the one
        // that gets to report a failed job — which is why the `queue` tag is on the event and not lost to
        // whichever path happened to run first. Reported::firstTime() is what makes "exactly one event"
        // (FR-1 a) true across the two paths.
        $this->app->make('events')->listen(JobFailed::class, JobFailureListener::class);
    }

    /**
     * The budget: at most request_timeout_ms of wall clock AFTER the response, for the whole drain.
     * Whatever the deadline cuts short stays in the queue for the next flush rather than holding the
     * worker, and nothing in here can reach the application — including a transport that throws in breach
     * of its own contract, and including a container that has already been torn down.
     */
    public function flushAfterResponse(): void
    {
        try {
            $budgetMs = $this->app->make(Config::class)->requestTimeoutMs;
            $this->app->make(Client::class)->flush(microtime(true) + ($budgetMs / 1000));
        } catch (\Throwable) {
            // Deliberately silent. FR-2 b, and the loop guard: this package never reports its own failure.
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

    /** @return list<class-string> */
    public function provides(): array
    {
        return [Config::class, Client::class, Logger::class, VerdictTransport::class];
    }
}

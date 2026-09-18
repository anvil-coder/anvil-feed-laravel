<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests;

use AnvilFeed\AnvilFeed;
use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\Laravel\AnvilFeedServiceProvider;
use AnvilFeed\Laravel\Reported;
use AnvilFeed\Laravel\VerdictTransport;
use AnvilFeed\Tests\Support\RecordingTransport;
use AnvilFeed\Transport\Transport;
use Monolog\Handler\TestHandler;

/**
 * The booted application every test in this package runs against.
 *
 * Two rules it enforces for the whole suite:
 *
 *  - the transport is a double, and the endpoint points at the discard port. The core registers a
 *    shutdown flush the first time an event is queued, so a test that leaves an event in the queue would
 *    otherwise post it to the real ingest when phpunit's process ends.
 *  - logging goes through Laravel's own LogManager into a Monolog TestHandler. Swapping the `log` binding
 *    for a bare PSR-3 recorder would prove the recorder works, not that the provider reaches the channel
 *    an application actually configures.
 */
abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected const TOKEN = 'anv_srv_0123456789abcdef0123456789abcdef';

    protected RecordingTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        Reported::forget();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        AnvilFeed::reset();
        Reported::forget();
        parent::tearDown();
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [AnvilFeedServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'testbench-app');
        $app['config']->set('logging.default', 'anvil-test');
        $app['config']->set('logging.channels.anvil-test', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]);

        $app['config']->set('anvil-feed.token', static::TOKEN);
        $app['config']->set('anvil-feed.service', 'disponio-api');
        $app['config']->set('anvil-feed.environment', 'testing');
        // Nothing may leave this machine, whatever a test forgets to bind: 9 is the discard port.
        $app['config']->set('anvil-feed.endpoint', 'http://127.0.0.1:9/api/feed');

        $app->instance(Transport::class, $this->transport);
    }

    /** Rebuilds config, transport and client after a test changed the configuration. */
    protected function rebuildSdk(): void
    {
        $this->app->forgetInstance(Config::class);
        $this->app->forgetInstance(VerdictTransport::class);
        $this->app->forgetInstance(Client::class);
        AnvilFeed::reset();
        AnvilFeed::init($this->app->make(Client::class));
    }

    protected function useTransport(Transport $transport): void
    {
        $this->app->instance(Transport::class, $transport);
        $this->rebuildSdk();
    }

    protected function client(): Client
    {
        return $this->app->make(Client::class);
    }

    /** Every log line this application wrote, level included. @return list<string> */
    protected function logLines(): array
    {
        $lines = [];
        foreach ($this->app['log']->driver()->getLogger()->getHandlers() as $handler) {
            if (!$handler instanceof TestHandler) {
                continue;
            }
            foreach ($handler->getRecords() as $record) {
                $lines[] = $record->level->getName() . ': ' . $record->message;
            }
        }

        return $lines;
    }

    /** @return list<string> */
    protected function logLinesContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->logLines(),
            static fn (string $line): bool => str_contains($line, $needle)
        ));
    }

    /**
     * The single event of the one request that was posted, decoded.
     *
     * @return array<string, mixed>
     */
    protected function onlyPostedEvent(): array
    {
        self::assertCount(1, $this->transport->calls, 'exactly one request should have been posted');
        $decoded = json_decode($this->transport->calls[0]['body'], true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('event_id', $decoded, 'a single event is posted as an object, not an array');

        return $decoded;
    }
}

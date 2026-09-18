<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests;

use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\Laravel\AnvilFeedServiceProvider;
use AnvilFeed\Laravel\PeriodicFlush;
use AnvilFeed\Laravel\VerdictTransport;
use AnvilFeed\Transport\DirectTransport;
use AnvilFeed\Transport\PsrTransport;
use Illuminate\Support\ServiceProvider;

final class ServiceProviderTest extends TestCase
{
    public function testTheContainerHandsOutOneConfiguredClient(): void
    {
        $config = $this->app->make(Config::class);

        self::assertSame('disponio-api', $config->service);
        self::assertSame('testing', $config->environment);
        self::assertTrue($config->enabled);
        self::assertSame($this->app->make(Client::class), $this->app->make(Client::class));
    }

    public function testTheConfigFileIsPublishableUnderItsTag(): void
    {
        $paths = ServiceProvider::pathsToPublish(AnvilFeedServiceProvider::class, 'anvil-feed-config');

        self::assertCount(1, $paths);
        self::assertStringEndsWith('config/anvil-feed.php', (string) array_key_first($paths));
        self::assertStringEndsWith('anvil-feed.php', (string) reset($paths));
    }

    public function testServiceAndEnvironmentFallBackToWhatTheApplicationCallsItself(): void
    {
        $this->app['config']->set('anvil-feed.service', null);
        $this->app['config']->set('anvil-feed.environment', null);
        $this->app['config']->set('app.name', 'invoicing-api');
        $this->app['config']->set('app.env', 'staging');
        $this->rebuildSdk();

        $config = $this->app->make(Config::class);
        self::assertSame('invoicing-api', $config->service);
        self::assertSame('staging', $config->environment);
        self::assertTrue($config->enabled);
    }

    public function testAnAppEnvLaravelWouldAcceptButTheIngestWouldNotIsRepaired(): void
    {
        $this->app['config']->set('anvil-feed.environment', null);
        $this->app['config']->set('app.env', 'Prod_EU');
        $this->rebuildSdk();

        self::assertSame('prod-eu', $this->app->make(Config::class)->environment);
        self::assertTrue($this->app->make(Config::class)->enabled);
    }

    public function testWithoutATokenTheSdkIsDisabledSilentlyAndSaysItExactlyOnce(): void
    {
        $this->app['config']->set('anvil-feed.token', null);
        $this->rebuildSdk();
        $this->rebuildSdk();   // a second boot of the same process must not say it again

        $client = $this->client();
        self::assertFalse($client->config()->enabled);
        self::assertNull($client->captureException(new \RuntimeException('boom')));
        self::assertSame(0, $client->queue()->count());

        $client->flush();
        self::assertSame([], $this->transport->calls, 'a disabled client must not post anything');

        self::assertCount(1, $this->logLinesContaining('no ingest token'));
        self::assertSame([], $this->logLinesContaining('anvil-feed: capture failed'));
    }

    public function testTerminatingFlushesWhatTheRequestQueued(): void
    {
        $id = $this->client()->captureMessage('after the response, never during it');
        self::assertIsString($id);
        self::assertSame(1, $this->client()->queue()->count());
        self::assertSame([], $this->transport->calls, 'nothing may be posted inside the request path');

        $this->app->terminate();

        self::assertSame(0, $this->client()->queue()->count());
        self::assertCount(1, $this->transport->calls);
        self::assertStringContainsString($id, $this->transport->calls[0]['body']);
    }

    public function testTheFlushAfterTheResponseIsBoundedByRequestTimeoutMs(): void
    {
        $this->app['config']->set('anvil-feed.request_timeout_ms', 750);
        $this->rebuildSdk();
        $this->client()->captureMessage('bounded');

        $this->app->terminate();

        self::assertCount(1, $this->transport->calls);
        $budget = $this->transport->budgets()[0];
        self::assertIsInt($budget);
        self::assertGreaterThan(0, $budget);
        self::assertLessThanOrEqual(750, $budget, 'the socket must never get more than the flush budget');
    }

    /**
     * Client::flush() logs its own failures and Sender logs the transport's, so what reaches this catch is
     * the case nobody has a diagnosis for. Swallowing it makes every event queue and nothing ever leave,
     * permanently and invisibly.
     */
    public function testAFlushThatCannotEvenStartSaysSoOnceAndNeverThrows(): void
    {
        $this->app->bind(Config::class, static function (): Config {
            throw new \RuntimeException('the config binding is broken');
        });

        $this->app->make(PeriodicFlush::class)->now();
        $this->app->make(PeriodicFlush::class)->now();
        $this->app->terminate();

        self::assertCount(1, $this->logLinesContaining('the flush after the response could not run'));
    }

    public function testTheBundledTransportIsTheDefault(): void
    {
        $this->app->forgetInstance(\AnvilFeed\Transport\Transport::class);
        unset($this->app[\AnvilFeed\Transport\Transport::class]);
        $this->rebuildSdk();

        self::assertInstanceOf(DirectTransport::class, $this->app->make(VerdictTransport::class)->inner());
    }

    public function testPsrIsUsedOnlyWhenItIsConfiguredAndBound(): void
    {
        $this->app->forgetInstance(\AnvilFeed\Transport\Transport::class);
        unset($this->app[\AnvilFeed\Transport\Transport::class]);
        $this->app['config']->set('anvil-feed.transport', 'psr');
        $this->rebuildSdk();

        self::assertInstanceOf(
            DirectTransport::class,
            $this->app->make(VerdictTransport::class)->inner(),
            'psr without a PSR-18 client in the container must fall back rather than fail to boot'
        );
        self::assertCount(1, $this->logLinesContaining('no PSR-18 client is bound'));

        $this->app->instance(\Psr\Http\Client\ClientInterface::class, new Support\StubPsrClient());
        $this->app->instance(\Psr\Http\Message\RequestFactoryInterface::class, new \Nyholm\Psr7\Factory\Psr17Factory());
        $this->app->instance(\Psr\Http\Message\StreamFactoryInterface::class, new \Nyholm\Psr7\Factory\Psr17Factory());
        $this->rebuildSdk();

        self::assertInstanceOf(PsrTransport::class, $this->app->make(VerdictTransport::class)->inner());
    }
}

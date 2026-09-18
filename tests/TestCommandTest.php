<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests;

use AnvilFeed\Laravel\Tests\Support\RejectingTransport;
use AnvilFeed\Laravel\Tests\Support\ReplayingTransport;
use AnvilFeed\Tests\Support\RecordingTransport;
use AnvilFeed\Transport\Response;

/**
 * FR-8: a developer who has just added the package runs one command and learns which of four things is
 * true. Every one of the four is asserted here, because "it printed something" is the proxy that lets a
 * `no_binding` rejection look like a successful install.
 */
final class TestCommandTest extends TestCase
{
    public function testAnAcceptedEventIsReportedAsAcceptedWithItsId(): void
    {
        $this->artisan('anvil-feed:test')
            ->expectsOutputToContain('service:     disponio-api')
            ->expectsOutputToContain('environment: testing')
            ->expectsOutputToContain('event_id:')
            ->expectsOutputToContain('verdict:     accepted')
            ->assertExitCode(0)
            ->run();

        self::assertCount(1, $this->transport->calls);
        $posted = json_decode($this->transport->calls[0]['body'], true);
        self::assertSame('AnvilFeed.TestEvent', $posted['exception']['type']);
    }

    public function testTheTokenLineShowsTheFormatAndFourCharactersOfTheSecret(): void
    {
        $this->artisan('anvil-feed:test')
            ->expectsOutputToContain('token:       anv_srv_0123… (40 characters)')
            ->assertExitCode(0)
            ->run();
    }

    /**
     * A host may bind a transport that replays one Response object — a stub, a cache, a circuit breaker.
     * Deciding "nothing was posted" by comparing that object to the previous one calls a post that really
     * happened "nothing reached the ingest", which is the wrong diagnosis in the one command whose job is
     * the right one. The count says what happened.
     */
    public function testTheCommandDecidesWhatWasPostedByCountAndNotByObjectIdentity(): void
    {
        $transport = new ReplayingTransport();
        $this->useTransport($transport);

        $this->artisan('anvil-feed:test')->assertExitCode(0)->run();

        // The second run posts a second body and gets the FIRST answer back, which names the first event.
        $this->artisan('anvil-feed:test')
            ->expectsOutputToContain('202 without a verdict for this')
            ->doesntExpectOutputToContain('Nothing reached the ingest')
            ->assertExitCode(1)
            ->run();

        // Three: the first run's post, the second run's post, and the one more attempt the core grants an
        // event the ingest answered 202 about without naming it.
        self::assertSame(3, $transport->posts);
    }

    public function testARejectedEventNamesTheIngestsOwnReasonCode(): void
    {
        $this->useTransport(new RejectingTransport('no_binding', "no binding for environment 'testing'"));

        $this->artisan('anvil-feed:test')
            ->expectsOutputToContain('verdict:     rejected (no_binding)')
            ->expectsOutputToContain("no binding for environment 'testing'")
            ->expectsOutputToContain('Connect this environment in the Anvil cockpit')
            ->assertExitCode(1)
            ->run();
    }

    public function testAnIngestThatCannotBeReachedIsReportedAsTransient(): void
    {
        $this->useTransport(new RecordingTransport(
            Response::failure('connect timeout after 1000ms'),
        ));

        $this->artisan('anvil-feed:test')
            ->expectsOutputToContain('verdict:     failed transiently')
            ->expectsOutputToContain('connect timeout after 1000ms')
            ->assertExitCode(1)
            ->run();

        self::assertSame(1, $this->client()->queue()->count(), 'a transient failure keeps the event');
    }

    public function testAnUnknownTokenIsReportedAsRejectedAndNotAsAFailureToSend(): void
    {
        $this->useTransport(new RecordingTransport(
            new Response(401, '{"error":"unauthorized"}'),
        ));

        $this->artisan('anvil-feed:test')
            ->expectsOutputToContain('verdict:     rejected (unauthorized)')
            ->assertExitCode(1)
            ->run();
    }

    public function testWithoutATokenTheCommandSaysTheSdkIsDisabledAndSendsNothing(): void
    {
        $this->app['config']->set('anvil-feed.token', null);
        $this->rebuildSdk();

        $this->artisan('anvil-feed:test')
            ->expectsOutputToContain('token:       MISSING — set ANVIL_INGEST_TOKEN')
            ->expectsOutputToContain('verdict:     disabled')
            ->expectsOutputToContain('no ingest token')
            ->assertExitCode(1)
            ->run();

        self::assertSame([], $this->transport->calls);
    }
}

<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests;

use AnvilFeed\AnvilFeed;
use AnvilFeed\Laravel\Integration;
use AnvilFeed\Laravel\JobFailureListener;
use AnvilFeed\Laravel\Tests\Support\FakeJob;
use AnvilFeed\Laravel\Tests\Support\TeapotException;
use AnvilFeed\Laravel\Tests\Support\ThrowingTransport;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Routing\Router;

/**
 * What the integration is for: an exception nobody caught becomes exactly one event, with the request it
 * happened in attached, and the application notices nothing.
 */
final class IntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The one line an application writes in bootstrap/app.php, spelled against the handler this
        // application actually has:
        //   ->withExceptions(fn (Exceptions $e) => AnvilFeed\Laravel\Integration::handles($e))
        Integration::handles(new Exceptions($this->app->make(ExceptionHandler::class)));
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->get('/boom', static fn () => throw new \RuntimeException('boom'))->name('boom.route');
        $router->get('/teapot', static fn () => throw new TeapotException('short and stout'));
        $router->get('/api/feed/events', static fn () => throw new \RuntimeException('our own ingest'));
        $router->get('/ok', static function (): string {
            AnvilFeed::captureMessage('a message from inside a request');

            return 'ok';
        });
    }

    public function testAnUnhandledExceptionBecomesExactlyOneEventCarryingTheRequest(): void
    {
        $response = $this->get('/boom');

        $response->assertStatus(500);

        $event = $this->onlyPostedEvent();
        self::assertSame('RuntimeException', $event['exception']['type']);
        self::assertSame('RuntimeException: boom', $event['title']);
        self::assertSame('GET', $event['request']['method']);
        self::assertStringEndsWith('/boom', $event['request']['url']);
        self::assertArrayNotHasKey(
            'status_code',
            $event['request'],
            'the status of an exception that is only on its way to becoming a 500 would be a guess'
        );
        self::assertSame(['route' => 'boom.route'], $event['tags']);
        self::assertSame('disponio-api', $event['service']);
        self::assertSame('testing', $event['environment']);
    }

    public function testAnExceptionThatKnowsItsStatusCarriesIt(): void
    {
        $this->get('/teapot')->assertStatus(418);

        self::assertSame(418, $this->onlyPostedEvent()['request']['status_code']);
    }

    public function testLaravelKeepsReportingTheExceptionItself(): void
    {
        $this->get('/boom');

        self::assertNotEmpty(
            $this->logLinesContaining('boom'),
            'the reportable callback returns nothing, so the application keeps its own log line'
        );
    }

    public function testAnExceptionOnAnIgnoredRequestPathIsNeverReported(): void
    {
        $this->get('/api/feed/events')->assertStatus(500);

        self::assertSame([], $this->transport->calls);
        self::assertSame(0, $this->client()->queue()->count());
    }

    public function testAFailedJobBecomesOneEventTaggedQueue(): void
    {
        $this->app->make('events')->dispatch($this->jobFailed(new \RuntimeException('the job broke')));

        $this->client()->flush();

        $event = $this->onlyPostedEvent();
        self::assertSame('queue-worker', $event['tags']['queue']);
        self::assertSame('App\\Jobs\\SendInvoice', $event['tags']['job']);
        self::assertSame('redis', $event['tags']['connection']);
        self::assertArrayNotHasKey('request', $event);
    }

    /**
     * Laravel reports a failed job twice — Worker::handleJobException() fires JobFailed, and
     * Worker::runJob() hands the same throwable to the exception handler afterwards. One fault, one event.
     */
    public function testTheSameThrowableTravellingBothPathsIsStillOneEvent(): void
    {
        $error = new \RuntimeException('the job broke');

        $this->app->make('events')->dispatch($this->jobFailed($error));
        $this->app->make(ExceptionHandler::class)->report($error);

        $this->client()->flush();

        $event = $this->onlyPostedEvent();
        self::assertSame('queue-worker', $event['tags']['queue']);
    }

    public function testATransportThatThrowsCostsTheApplicationNothing(): void
    {
        $transport = new ThrowingTransport();
        $this->useTransport($transport);

        $this->get('/ok')->assertStatus(200)->assertSee('ok');

        self::assertSame(1, $transport->calls, 'the flush really did reach the broken transport');
        self::assertSame(1, $this->client()->queue()->count(), 'and the event is kept for the next flush');
    }

    public function testAnExceptionRaisedWhileTheSdkIsSendingIsNotReportedAgain(): void
    {
        $transport = new ThrowingTransport();
        $this->useTransport($transport);

        $this->client()->captureMessage('the first one');
        $this->client()->flush();

        // The transport blew up inside the flush. Nothing about that failure may become an event of its
        // own — that is the loop every crash reporter has to be unable to enter.
        self::assertSame(1, $this->client()->queue()->count());
        self::assertSame([], $this->logLinesContaining('anvil-feed: capture failed'));
    }

    private function jobFailed(\Throwable $error): JobFailed
    {
        return new JobFailed('redis', new FakeJob(), $error);
    }
}

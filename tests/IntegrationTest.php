<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests;

use AnvilFeed\AnvilFeed;
use AnvilFeed\Laravel\Integration;
use AnvilFeed\Laravel\JobFailureListener;
use AnvilFeed\Laravel\Tests\Support\ExplodingGuard;
use AnvilFeed\Laravel\Tests\Support\FakeJob;
use AnvilFeed\Laravel\Tests\Support\FakeUser;
use AnvilFeed\Laravel\Tests\Support\SelfReportingException;
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
        SelfReportingException::$reported = 0;
    }

    /** The guard an application would have; never asked to FIND a user by this package. */
    private function useGuard(ExplodingGuard $guard): void
    {
        $this->app['auth']->extend('anvil-exploding', static fn (): ExplodingGuard => $guard);
        $this->app['config']->set('auth.guards.anvil', ['driver' => 'anvil-exploding', 'provider' => 'users']);
        $this->app['config']->set('auth.defaults.guard', 'anvil');
        $this->app->make('auth')->guard();   // authentication ran in this request, as middleware would
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->get('/boom', static fn () => throw new \RuntimeException('boom'))->name('boom.route');
        $router->get('/teapot', static fn () => throw new TeapotException('short and stout'));
        $router->get('/api/feed/events', static fn () => throw new \RuntimeException('our own ingest'));
        $router->get('/self-report', static fn () => throw new SelfReportingException('the payment was declined'));
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

    public function testAnExceptionThatReportsItselfStillBecomesAnEvent(): void
    {
        $this->get('/self-report')->assertStatus(500);

        self::assertSame(1, SelfReportingException::$reported, "the application's own report() still ran");
        $event = $this->onlyPostedEvent();
        self::assertSame(SelfReportingException::class, $event['exception']['type']);
        self::assertStringEndsWith('/self-report', $event['request']['url']);
    }

    public function testAnAuthenticatedUsersIdTravelsAndNothingElseAboutThem(): void
    {
        // Without this the frames quote THIS file, and the two strings below come back as source code
        // rather than as fields — a red test about the scrubber that is really about the test's own text.
        $this->app['config']->set('anvil-feed.context_lines', 0);
        $this->rebuildSdk();
        $this->actingAs(new FakeUser('u-42'));

        $this->get('/boom');

        $event = $this->onlyPostedEvent();
        self::assertSame('u-42', $event['user']['id']);
        $whole = (string) json_encode($event);
        self::assertStringNotContainsString('Ada Lovelace', $whole);
        self::assertStringNotContainsString('ada@example.com', $whole);
    }

    /**
     * The request-path rule, asserted where it is easiest to break: an exception thrown BECAUSE the
     * database is down must not make this SDK go and ask the database who is logged in.
     */
    public function testReportingNeverAsksAGuardToFindAUser(): void
    {
        $this->useGuard($guard = new ExplodingGuard(hasUser: false));

        $this->get('/boom')->assertStatus(500);

        self::assertSame(0, $guard->userCalls, 'a report may not trigger a session read or a users query');
        // Laravel's own handler does ask (Handler::context() logs Auth::id()); that is the application's
        // decision about its own log, and it is not this package's to make on its behalf.
        self::assertArrayNotHasKey('user', $this->onlyPostedEvent());
    }

    public function testAGuardThatThrowsCostsTheEventNothing(): void
    {
        $this->useGuard($guard = new ExplodingGuard(hasUser: true));

        $this->get('/boom')->assertStatus(500);

        self::assertSame(1, $guard->userCalls, 'a user that is already there is read, exactly once');
        $event = $this->onlyPostedEvent();
        self::assertArrayNotHasKey('user', $event);
        self::assertSame('GET', $event['request']['method'], 'and the rest of the context survived');
    }

    /**
     * An application served from https://host/app/ has its ingest at /app/api/feed/events, which the
     * shipped default `/api/feed/**` does not match on the full path.
     */
    public function testTheLoopGuardHonoursAnApplicationMountedUnderASubPath(): void
    {
        $this->call('GET', 'http://localhost/app/api/feed/events', [], [], [], [
            'SCRIPT_NAME' => '/app/index.php',
            'SCRIPT_FILENAME' => '/app/index.php',
            'PHP_SELF' => '/app/index.php',
        ]);

        self::assertSame([], $this->transport->calls);
        self::assertSame(0, $this->client()->queue()->count());
    }

    /**
     * FR-2 c as this package can actually state it: the request path ENQUEUES. No socket is opened, and
     * the cost of building the event is what the README names rather than a p99 promise nobody measured.
     */
    public function testTheRequestPathEnqueuesAndOpensNoSocket(): void
    {
        $client = $this->client();
        $error = new \RuntimeException('a deep one');

        $started = hrtime(true);
        for ($i = 0; $i < 10; $i++) {
            $client->captureException($error, ['request' => ['method' => 'GET', 'url' => 'http://x/y']]);
        }
        $elapsedMs = (hrtime(true) - $started) / 1e6;

        self::assertSame([], $this->transport->calls, 'nothing may be posted while a request is served');
        self::assertSame(10, $client->queue()->count());
        self::assertLessThan(
            250.0,
            $elapsedMs,
            sprintf('ten captures of a real exception took %.1f ms', $elapsedMs)
        );
    }

    private function jobFailed(\Throwable $error): JobFailed
    {
        return new JobFailed('redis', new FakeJob(), $error);
    }
}

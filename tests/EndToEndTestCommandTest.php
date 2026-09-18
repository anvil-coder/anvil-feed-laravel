<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests;

use AnvilFeed\Transport\Transport;

/**
 * FR-8's four verdicts against a REAL ingest answer.
 *
 * {@see TestCommandTest} already proves the four against doubles this repository wrote, which is enough to
 * hold the wording and the exit code and not enough to say that the answers those doubles replay are the
 * answers the ingest gives. This class runs `anvil-feed:test` against `conformance/stub/server.mjs` — the
 * replay of dark-factory's published contract — over a socket, with the transport the service provider
 * builds for itself when a host binds none. The verdict a developer reads in the first fifteen minutes is
 * therefore read off the contract's own status codes and reason codes.
 *
 * The one that matters most is `no_binding`: the token is valid, the event arrived, the ingest refused it,
 * and every proxy for success — "it was sent", "the queue is empty", "no exception" — reports that as a
 * pass. Its twin on the Java side is
 * `java/anvil-feed-spring-boot-starter/.../AnvilFeedTestEventEndToEndTest.java`, and the two assert the
 * same five lines because C17 makes them one vocabulary.
 */
final class EndToEndTestCommandTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** @var resource|null */
    private static $process = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $journal = self::ROOT . '/conformance/stub/.journals/laravel.jsonl';
        if (!is_dir(dirname($journal))) {
            mkdir(dirname($journal), 0o777, true);
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            'exec node ' . escapeshellarg(self::ROOT . '/conformance/stub/server.mjs'),
            $descriptors,
            $pipes,
            null,
            ['STUB_JOURNAL' => $journal, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin']
        );
        if (!is_resource($process)) {
            self::fail('the ingest stub could not be started. Node 22+ has to be on PATH; see '
                . 'conformance/stub/README.md.');
        }
        self::$process = $process;

        $listening = fgets($pipes[1]);
        if (!is_string($listening) || preg_match('/listening (\d+)/', $listening, $matches) !== 1) {
            self::fail('the ingest stub did not report a port. stdout: ' . var_export($listening, true)
                . ' stderr: ' . var_export(stream_get_contents($pipes[2]), true));
        }
        self::$port = (int) $matches[1];
        fgets($pipes[1]);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    /**
     * Points the application at the stub and drops the bound double, so the provider builds the
     * `DirectTransport` a real installation runs.
     *
     * @param array<string, mixed> $config
     */
    private function useTheStub(string $scenario = 'accepted', array $config = []): void
    {
        $this->app->forgetInstance(Transport::class);
        $this->app['config']->set(
            'anvil-feed.endpoint',
            sprintf('http://127.0.0.1:%d/laravel/%s/api/feed', self::$port, $scenario)
        );
        foreach ($config as $key => $value) {
            $this->app['config']->set('anvil-feed.' . $key, $value);
        }
        $this->rebuildSdk();
    }

    /** Tells the stub what to answer to the next requests of this run, one scenario per request. */
    private function script(string ...$answers): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['run' => 'laravel', 'answers' => $answers], JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
        ]]);
        $answer = @file_get_contents(sprintf('http://127.0.0.1:%d/__control', self::$port), false, $context);
        self::assertIsString($answer);
        self::assertStringContainsString('"ok":true', $answer);
    }

    public function testAcceptedIsTheIngestsOwnAnswerAndNamesTheIdToSearchFor(): void
    {
        $this->useTheStub('accepted', ['environment' => 'production']);

        $this->artisan('anvil-feed:test')
            ->expectsOutputToContain('event_id:')
            ->expectsOutputToContain('verdict:     accepted')
            ->expectsOutputToContain("Open the project's Incidents inbox")
            ->assertExitCode(0)
            ->run();
    }

    /**
     * The environment is not bound at the stub, exactly as it is not bound in a cockpit where step 4 of
     * the guide was skipped. Reason code and message both come from the contract.
     */
    public function testAnUnboundEnvironmentIsReportedAsNoBindingWithTheIngestsOwnMessage(): void
    {
        $this->useTheStub('accepted', ['environment' => 'staging']);

        $this->artisan('anvil-feed:test')
            ->expectsOutputToContain('verdict:     rejected (no_binding)')
            ->expectsOutputToContain("no binding for environment 'staging'")
            ->expectsOutputToContain('Connect this environment in the Anvil cockpit')
            ->assertExitCode(1)
            ->run();
    }

    public function testATransientStoreFailureIsReportedAsTransientAndTheEventStaysQueued(): void
    {
        $this->useTheStub('accepted', ['environment' => 'production']);
        $this->script('transient');

        $this->artisan('anvil-feed:test')
            ->expectsOutputToContain('verdict:     failed transiently')
            ->expectsOutputToContain('The event stays in the queue')
            ->assertExitCode(1)
            ->run();
    }

    public function testATokenTheIngestDoesNotKnowIsReportedAsUnauthorisedRatherThanAsAFailureToSend(): void
    {
        $this->useTheStub('accepted', ['environment' => 'production', 'token' => 'anv_srv_too-short']);

        $this->artisan('anvil-feed:test')
            ->expectsOutputToContain('verdict:     rejected (unauthorized)')
            ->assertExitCode(1)
            ->run();
    }

    /** No token: nothing reaches the socket at all, and the line says which value switched the SDK off. */
    public function testDisabledIsAVerdictOfItsOwnAndReachesNoSocket(): void
    {
        $this->useTheStub('accepted', ['environment' => 'production', 'token' => null]);

        $this->artisan('anvil-feed:test')
            ->expectsOutputToContain('verdict:     disabled')
            ->expectsOutputToContain('ANVIL_INGEST_TOKEN')
            ->doesntExpectOutputToContain('event_id:')
            ->assertExitCode(1)
            ->run();
    }
}

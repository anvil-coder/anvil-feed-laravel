<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests\Support;

use AnvilFeed\Tests\Support\RecordingTransport;
use AnvilFeed\Transport\Response;
use AnvilFeed\Transport\Transport;

/**
 * A host transport that hands back the SAME Response object every time — a stub, a cache, a circuit
 * breaker replaying its last answer. Perfectly legal: the contract says what post() returns, not that it
 * must be a new object.
 *
 * It is here because `anvil-feed:test` used to decide "nothing was posted" by comparing the last Response
 * to the previous one. Against this transport that comparison reads a post that really happened as
 * "nothing reached the ingest" — the wrong diagnosis, in the one command whose job is the right one.
 */
final class ReplayingTransport implements Transport
{
    public int $posts = 0;

    private ?Response $answer = null;

    public function post(string $url, string $body, ?int $timeoutMs = null): Response
    {
        $this->posts++;

        return $this->answer ??= new Response(202, RecordingTransport::acceptEverything($body));
    }
}

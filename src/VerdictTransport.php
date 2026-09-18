<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel;

use AnvilFeed\Transport\Response;
use AnvilFeed\Transport\Transport;

/**
 * The configured transport, plus the last answer it got.
 *
 * It exists for one reason: `anvil-feed:test` has to tell a developer what the ingest SAID — accepted,
 * or rejected with a reason code — and the Sender turns that answer into log lines and an empty queue.
 * "The queue is empty" is exactly the proxy that would report a `no_binding` rejection as success, which
 * is the one failure the fifteen-minute guide (FR-8) exists to catch.
 *
 * It adds no behaviour: one delegation and one assignment, and an exception out of the inner transport
 * travels on untouched, because the Sender is the layer that is allowed to decide what a failure means.
 */
final class VerdictTransport implements Transport
{
    private ?Response $last = null;

    public function __construct(private readonly Transport $inner)
    {
    }

    public function post(string $url, string $body, ?int $timeoutMs = null): Response
    {
        return $this->last = $this->inner->post($url, $body, $timeoutMs);
    }

    /** The last answer, or null when nothing has been posted in this process. */
    public function last(): ?Response
    {
        return $this->last;
    }

    /** What the configuration actually chose — asserted by the transport tests. */
    public function inner(): Transport
    {
        return $this->inner;
    }
}

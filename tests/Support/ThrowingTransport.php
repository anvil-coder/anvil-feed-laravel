<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests\Support;

use AnvilFeed\Transport\Response;
use AnvilFeed\Transport\Transport;

/**
 * A transport in breach of its own contract: Transport::post() is documented never to throw.
 *
 * The point of the double is precisely that the application must survive somebody else's breach — a host
 * that binds its own transport, a PSR-18 client whose DNS resolver throws — with a 200 still going out.
 */
final class ThrowingTransport implements Transport
{
    public int $calls = 0;

    public function post(string $url, string $body, ?int $timeoutMs = null): Response
    {
        $this->calls++;

        throw new \RuntimeException('the transport itself is broken');
    }
}

<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers every request with 202 and accepts nothing it is not asked to.
 *
 * It exists so that "the provider builds a PsrTransport when one is configured AND bound" is a fact and
 * not a claim: without a client in the container the provider is required to fall back, and a test that
 * only ever sees the fallback cannot tell the two apart.
 */
final class StubPsrClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new \Nyholm\Psr7\Response(202, [], '{"accepted":[],"rejected":[]}');
    }
}

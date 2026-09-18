<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests\Support;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * An exception that knows its own HTTP status without extending Symfony's HttpException.
 *
 * It has to be both: Laravel's handler never reports a Symfony HttpException (it is on the framework's
 * internal don't-report list), so a test that used one would assert that nothing happens. This one is
 * reported like any other RuntimeException and still carries the status the event should show.
 */
final class TeapotException extends \RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        return 418;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}

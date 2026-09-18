<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests\Support;

/**
 * Laravel's documented "reportable exception" idiom: an exception class with its own report() method.
 *
 * `Handler::reportThrowable()` calls it and returns — the reportable callbacks below are never reached.
 * Every application that uses this idiom for the faults it cares most about would otherwise be invisible
 * to Anvil, with nothing in the log saying so.
 */
final class SelfReportingException extends \RuntimeException
{
    public static int $reported = 0;

    public function report(): void
    {
        self::$reported++;
    }
}

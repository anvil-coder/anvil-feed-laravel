<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel;

/**
 * Which throwables this process has already turned into an event.
 *
 * Laravel reports a failed queue job twice: Worker::handleJobException() fires JobFailed, and
 * Worker::runJob() then hands the same throwable to the exception handler, where the reportable callback
 * of Integration sits. Two reports of one fault are two events with two ids and one fingerprint — the
 * cockpit would show a doubled count for every failing job, and FR-1 a says exactly one event.
 *
 * A WeakMap and not an array: the key is the throwable itself, so an entry disappears with the object it
 * is about. There is nothing to bound, nothing to expire and nothing to leak.
 */
final class Reported
{
    /** @var \WeakMap<\Throwable, true>|null */
    private static ?\WeakMap $seen = null;

    /** True exactly once per throwable instance. Never throws. */
    public static function firstTime(\Throwable $error): bool
    {
        try {
            self::$seen ??= new \WeakMap();
            if (isset(self::$seen[$error])) {
                return false;
            }
            self::$seen[$error] = true;

            return true;
        } catch (\Throwable) {
            // A registry that fails must not suppress the report it was meant to deduplicate.
            return true;
        }
    }

    /** For tests and for a long-running worker that wants the slate clean. */
    public static function forget(): void
    {
        self::$seen = null;
    }
}

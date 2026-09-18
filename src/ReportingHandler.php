<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;

/**
 * The application's exception handler, with one line in front of it.
 *
 * Laravel's own handler short-circuits on an exception that carries its own `report()` method
 * (`Illuminate\Foundation\Exceptions\Handler::reportThrowable()`: if the exception is reportable and its
 * report() does not return false, the reportable callbacks below are never reached). That idiom is in
 * Laravel's own documentation, and an application that uses it for its `PaymentDeclinedException` would
 * have installed this package, seen `anvil-feed:test` print `accepted`, and never received an event for
 * the one fault it cares about.
 *
 * So the capture happens BEFORE the handler decides, and the handler's own decision is left completely
 * alone afterwards. `shouldReport()` is still the gate: a 404, a validation failure and the rest of the
 * framework's don't-report list are not incidents and stay out.
 *
 * `Reported` is what keeps this from doubling the ordinary case, where the reportable callback registered
 * by Integration::handles() would report the same throwable a moment later.
 *
 * Everything that is not part of the ExceptionHandler contract is passed through untouched, so a caller
 * that reaches for Laravel's own `reportable()`, `renderable()` or `stopIgnoring()` on the container's
 * handler still gets them.
 */
final class ReportingHandler implements ExceptionHandler
{
    public function __construct(private readonly ExceptionHandler $inner)
    {
    }

    public function report(\Throwable $e): void
    {
        try {
            if ($this->inner->shouldReport($e)) {
                Integration::report($e);
            }
        } catch (\Throwable) {
            // FR-2 b: the application's own reporting must happen whatever this SDK does.
        }

        $this->inner->report($e);
    }

    public function shouldReport(\Throwable $e): bool
    {
        return (bool) $this->inner->shouldReport($e);
    }

    /** @param \Illuminate\Http\Request $request */
    public function render($request, \Throwable $e)
    {
        return $this->inner->render($request, $e);
    }

    /** @param \Symfony\Component\Console\Output\OutputInterface $output */
    public function renderForConsole($output, \Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }

    /** The handler an application configured, unwrapped. */
    public function inner(): ExceptionHandler
    {
        return $this->inner;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }
}

<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel;

use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\LoopGuard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;

/**
 * FR-1 c: the whole diff of an integration is init and configuration. One line in bootstrap/app.php:
 *
 *   ->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions) {
 *       AnvilFeed\Laravel\Integration::handles($exceptions);
 *   })
 *
 * The reportable callback returns nothing, which means Laravel's own reporting continues afterwards.
 * Returning false would silence the application's log, and an SDK that quietly turns off somebody's
 * logging is an SDK that gets removed the first time an incident needs the log.
 *
 * `Exceptions` lives in laravel/framework, which is not among this package's requirements: it is the
 * application skeleton's class, and an application calling this method necessarily has it. The package
 * depends on illuminate/support, illuminate/contracts and illuminate/console only.
 */
final class Integration
{
    public static function handles(Exceptions $exceptions): void
    {
        $exceptions->reportable(static function (\Throwable $error): void {
            self::report($error);
        });

        self::wrapTheHandler();
    }

    /**
     * The reportable callback above is not reached for every exception: Laravel's handler returns early
     * for an exception that carries its own `report()` method, which is an idiom in Laravel's own
     * documentation. So the container's exception handler is decorated as well, and the decorator captures
     * before the handler decides — gated by the handler's own shouldReport(), so the framework's
     * don't-report list (a 404, a validation failure) stays out. Reported::firstTime() keeps the ordinary
     * case, where both paths see the same throwable, at exactly one event.
     *
     * Deferred to booted() because this method normally runs INSIDE the resolution of the handler
     * (ApplicationBuilder::withExceptions registers it through afterResolving), and Container::extend()
     * applied at that moment would be overwritten by the singleton instance the resolution is about to
     * store.
     */
    private static function wrapTheHandler(): void
    {
        try {
            $app = \Illuminate\Container\Container::getInstance();
            if (!$app instanceof Application) {
                return;
            }
            $app->booted(static function (Application $booted): void {
                try {
                    $booted->extend(
                        ExceptionHandler::class,
                        static fn (ExceptionHandler $handler): ExceptionHandler => $handler instanceof ReportingHandler
                            ? $handler
                            : new ReportingHandler($handler)
                    );
                } catch (\Throwable) {
                    // An application whose handler cannot be decorated keeps the reportable callback,
                    // which covers everything but the self-reporting exceptions.
                }
            });
        } catch (\Throwable) {
            // As above. Nothing here may cost the application its boot.
        }
    }

    /**
     * Total, by contract. Every path out of here is a return: a container without the SDK in it, a
     * throwable that was already reported through the queue's own path, a context that cannot be read,
     * and — the one that matters — a failure of this SDK itself. FR-2 b, and the loop guard: the package
     * never reports its own failure, because the report would fail the same way.
     */
    public static function report(\Throwable $error): void
    {
        try {
            // The concrete class carries getInstance(); the contract is the type everything below uses.
            $container = \Illuminate\Container\Container::getInstance();
            if (!$container->bound(Client::class)) {
                return;
            }
            if (!Reported::firstTime($error)) {
                return;
            }
            if (self::ignoredUnderTheMountPath($container)) {
                return;
            }

            $container->make(Client::class)->captureException($error, self::context($container, $error));
        } catch (\Throwable) {
            // Deliberately empty. There is nowhere left to report to that would not fail the same way.
        }
    }

    /**
     * What was being served when it broke.
     *
     * `status_code` is filled only where it is genuinely known at report time — an HTTP exception carries
     * its own status, everything else is still on its way to becoming a 500 and would be a guess. FR-6 c:
     * a field that cannot be determined is left out, never filled with a placeholder.
     *
     * The route NAME is a tag rather than part of the request block, because that is what an operator
     * groups and filters by in the cockpit, and because schema 1.0's request block has three members.
     *
     * @return array<string, mixed>
     */
    private static function context(Container $container, \Throwable $error): array
    {
        $context = [];

        try {
            $request = self::requestOrNull($container);
            if ($request === null) {
                return $context;   // a console kernel, a queue worker, a boot-time failure
            }

            $status = self::statusOf($error);
            $context['request'] = array_filter([
                'method' => $request->getMethod(),
                'url' => $request->fullUrl(),
                'status_code' => $status,
            ], static fn (mixed $value): bool => $value !== null);

            $route = $request->route();
            $name = is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
            if (is_string($name) && $name !== '') {
                $context['tags'] = ['route' => $name];
            }

            $identifier = self::alreadyAuthenticatedId($container);
            if ($identifier !== null) {
                $context['user_id'] = $identifier;   // an id, never a name or an address
            }
        } catch (\Throwable) {
            // There may be no request at all, no session, no authenticated user, or a user provider that
            // hits a database that is exactly what broke. None of that may cost the event.
        }

        return $context;
    }

    /**
     * The request this process is serving, or null when it is not serving one.
     *
     * The question is deliberately NOT runningInConsole(): that reads PHP_SAPI, and Octane, FrankenPHP and
     * RoadRunner serve real HTTP requests from a CLI SAPI — every one of their events would lose its
     * request context. A console kernel and a queue worker do have a Request bound (built from $_SERVER at
     * bootstrap), and the thing $_SERVER does not have in a CLI process is REQUEST_METHOD.
     */
    private static function requestOrNull(Container $container): ?\Illuminate\Http\Request
    {
        if (!$container->bound('request')) {
            return null;
        }
        $request = $container->make('request');
        if (!$request instanceof \Illuminate\Http\Request) {
            return null;
        }

        // Both, because an Artisan command invoked from a CGI wrapper or a webhook runner inherits a
        // REQUEST_METHOD in $_SERVER on its own, and an event describing a request that is not what broke
        // is worse than an event with no request block.
        if ($request->server->get('REQUEST_METHOD') === null || $request->server->get('REQUEST_URI') === null) {
            return null;
        }

        return $request;
    }

    /**
     * The id of the user the request has ALREADY authenticated, or null.
     *
     * Never $request->user(): that calls the user resolver, which resolves the guard, which reads the
     * session and queries the users table. On the request that matters — an exception thrown because the
     * database is down — that is a fresh query against the dead database, inside the error path, with no
     * timeout of its own. The try/catch would keep it from throwing into the application; it would not
     * keep it from blocking. So the guard is asked whether it already has a user and is never asked to
     * find one.
     */
    private static function alreadyAuthenticatedId(Container $container): ?string
    {
        try {
            if (!$container->resolved('auth')) {
                return null;   // authentication never ran in this request
            }
            $guard = $container->make('auth')->guard();
            if (!method_exists($guard, 'hasUser') || $guard->hasUser() !== true) {
                return null;
            }
            $user = $guard->user();
            if ($user === null || !method_exists($user, 'getAuthIdentifier')) {
                return null;
            }
            $identifier = $user->getAuthIdentifier();

            return is_scalar($identifier) ? (string) $identifier : null;
        } catch (\Throwable) {
            return null;   // a custom guard of somebody else's is not worth an event
        }
    }

    /**
     * The loop guard, asked about the path the application is actually mounted at.
     *
     * `ignored_request_paths` reaches the core through `request.url`, and the core strips scheme and host
     * — so an application served from https://host/app/ has its ingest at /app/api/feed/events, which the
     * shipped default `/api/feed/**` does not match. Request::path() is relative to the mount point, so
     * this closes it without asking an operator to know the difference. The core still matches the full
     * path, so both spellings are honoured.
     */
    private static function ignoredUnderTheMountPath(Container $container): bool
    {
        try {
            $request = self::requestOrNull($container);
            if ($request === null) {
                return false;
            }
            $paths = $container->make(Config::class)->ignoredRequestPaths;
            if ($paths === []) {
                return false;
            }

            return (new LoopGuard($paths))->ignoresPath('/' . ltrim($request->path(), '/'));
        } catch (\Throwable) {
            return false;
        }
    }

    /** The status an exception already knows about itself; null when it would be a guess. */
    private static function statusOf(\Throwable $error): ?int
    {
        // No `use` and no class_exists(): instanceof against an interface the application does not have
        // is false, it does not autoload and it does not warn.
        if ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return $error->getStatusCode();
        }

        return null;
    }
}

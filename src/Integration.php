<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel;

use AnvilFeed\Client;
use Illuminate\Contracts\Container\Container;
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

            $user = $request->user();
            if ($user !== null && method_exists($user, 'getAuthIdentifier')) {
                $identifier = $user->getAuthIdentifier();
                if (is_scalar($identifier)) {
                    $context['user_id'] = (string) $identifier;   // an id, never a name or an address
                }
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

        return $request->server->get('REQUEST_METHOD') === null ? null : $request;
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

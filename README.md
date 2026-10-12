# anvil-feed-laravel

> **Read-only mirror.** This repository is the `php/anvil-feed-laravel` directory of the private
> `anvil-coder/anvil-feed` monorepo, split on every tag and every push to `main`. Nothing is edited here:
> a push to this repository is overwritten by the next mirror run. Issues and pull requests go to the
> monorepo's owners. The `tests/` directory reads the monorepo's `conformance/` fixtures and the core's
> tests through `../anvil-feed-php`, and does not run from this checkout; CI runs in the monorepo.
> Install with `composer require anvil-coder/anvil-feed-laravel`. Laravel 12 or 13, PHP 8.3 or newer.

The Laravel integration for the [Anvil Feed](https://github.com/anvil-coder/anvil-feed-php) SDK. An unhandled exception becomes one
event in the Anvil cockpit; the diff your application takes on is one line in `bootstrap/app.php` and a
token in `.env`.

**No socket is opened inside the request path.** Events are built and queued while the request is served,
and they go out afterwards: after the response (`terminating`), on the queue worker's own loop, or at the
end of the process. Every one of those is bounded by `request_timeout_ms`. Building an event is not free —
it reads the source files of the frames in the stack — and it is deliberately the only thing the request
pays for; the test suite asserts that ten captures of a real exception enqueue in well under a quarter of
a second (≈ 12 ms on the machine this was written on) and that nothing is posted during them.

Under php-fpm the response is already on the wire when the flush runs (`fastcgi_finish_request()`). On a
SAPI without it — mod_php, the built-in server — the flush is still bounded by `request_timeout_ms`, but
that budget is spent before the connection closes, so a user can observe it.

---

## Fifteen minutes, start to finish

### Step 0 — what has to exist in the cockpit first (3 minutes)

Open **https://app.anvil-coder.tech** and make sure you have all three of these. The verdict table in
step 4 tells you which one is missing if you skip one:

1. an **organisation** (sign up creates one),
2. a **project** for this application, with a **binding** for the *service name* and the *environment* you
   are about to configure — an event whose service/environment has nothing bound to it is refused with
   `no_binding`, and that is the single most likely outcome of step 4 for a first-time reader,
3. a **server ingest token** for that project. It starts with `anv_srv_`. Copy it now; it is shown once.

### Step 1 — install (1 minute)

```bash
composer require anvil-coder/anvil-feed-laravel
```

Laravel 12 or 13, PHP 8.3 or newer. Package discovery registers the provider; nothing to add.

The service provider is auto-discovered. Nothing is added to `config/app.php`.

### Step 2 — configure (2 minutes)

```bash
cat >> .env <<'ENV'
ANVIL_INGEST_TOKEN=anv_srv_replace_me
ANVIL_SERVICE=invoicing-api
ANVIL_ENVIRONMENT=local
ENV
```

`ANVIL_SERVICE` and `ANVIL_ENVIRONMENT` default to `config('app.name')` and `config('app.env')`. Set them
explicitly for this first run: a fresh checkout is `APP_ENV=local`, and the binding you created in step 0
has to name the same environment — otherwise step 4 answers `no_binding` and you will think the install
failed.

The ingest binds on a lower-case, dash-separated environment. `Prod_EU` is repaired to `prod-eu` and sent
that way, with one line in your log saying so; a name that cannot be repaired switches sending off rather
than reporting into the wrong project.

### Step 3 — one line in `bootstrap/app.php` (1 minute)

A default Laravel 12 or 13 skeleton already has a `->withExceptions()` call. Add the one line to its body:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        AnvilFeed\Laravel\Integration::handles($exceptions);     // <- this line
    })->create();
```

That is the entire diff. The callback returns nothing, so Laravel keeps logging the exception exactly as
it does today — an SDK that silences somebody's log is an SDK that gets removed the first time an incident
needs it.

### Step 4 — verify (2 minutes)

```bash
php artisan anvil-feed:test
```

It prints the endpoint, the service, the environment, the release, the first twelve characters of the
token, the `event_id` it queued — and then the ingest's own verdict:

| verdict | what it means | what to do |
|---|---|---|
| `accepted` | the ingest stored the event | search the cockpit inbox for the `event_id` |
| `rejected (no_binding)` | the token is valid; nothing is bound to it for this service/environment | step 0.2: add the binding for exactly the service and environment printed above |
| `rejected (binding_disabled)` | the binding exists and is switched off | switch it on |
| `rejected (unauthorized)` | 401 — the token is unknown, revoked, or another organisation's | step 0.3: issue a new token |
| `failed transiently` | it never arrived; the event is still in the queue | check `ANVIL_INGEST_ENDPOINT` and the log line beside it |
| `disabled` | the SDK is off — usually no `ANVIL_INGEST_TOKEN` | step 2 |

### Step 5 — throw something on purpose (3 minutes)

```bash
cat >> routes/web.php <<'PHP'

Route::get('/anvil-smoke-test', function () {
    throw new RuntimeException('anvil smoke test');
})->name('anvil.smoke');
PHP

php artisan serve &
sleep 2
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/anvil-smoke-test    # 500, as it should be
```

The event is in the cockpit inbox within a minute, with the request, the route name `anvil.smoke` and the
stack. Your application's own 500 page and log line are unchanged. Delete the route again.

---

## What an event carries

- The exception, its stack, and up to 20 lines of source around each frame.
- The request it happened in: method, full URL, and the status code where the exception already knows one.
  The route name travels as the tag `route`.
- The authenticated user's **id** — and only if the request had already authenticated one. This package
  never asks a guard to *find* a user: on the request that matters, an exception thrown because the
  database is down, that would be a fresh session read and a users query against the dead database.
- `service`, `environment`, `release` (the deployed commit, read from `.git` without running `git`).

Nine denylisted terms (`password`, `token`, `secret`, `authorization`, `api_key`, `cookie`, `email`,
`credit_card`, `iban`) are redacted in keys, in text and in URLs before anything is queued.

## What is and is not reported

Reported: everything the application's own exception handler reports, **including** exceptions that carry
their own `report()` method — Laravel's handler returns early for those, so this package captures before
the handler decides.

Not reported, because Laravel's handler does not consider them incidents (its `$internalDontReport` list):
`HttpException` and everything built on it (`abort(404)`, `abort(403)`), `ValidationException`,
`AuthenticationException`, `AuthorizationException`, `ModelNotFoundException`, `RecordsNotFoundException`,
`TokenMismatchException`, `HttpResponseException`, `BackedEnumCaseNotFoundException`,
`MultipleRecordsFoundException`, `SuspiciousOperationException`.

If you want one of them anyway, tell the framework — the package follows its decision:

```php
->withExceptions(function (Exceptions $exceptions) {
    AnvilFeed\Laravel\Integration::handles($exceptions);
    $exceptions->stopIgnoring(Illuminate\Auth\AuthenticationException::class);
})
```

Or report it yourself from anywhere: `AnvilFeed\AnvilFeed::captureException($e)`.

## Queue workers and long-running commands

A job that exhausts its attempts is reported through Laravel's `JobFailed` event and tagged `queue`, `job`
and `connection`. Laravel reports the same throwable a second time through the exception handler; this
package reports each throwable once.

A worker has no response to terminate after, so the events leave it on its own loop: every `Looping`,
`JobProcessed`, `JobFailed` and `CommandFinished` flushes if **`flush_interval_ms`** (default 2000) has
elapsed since the last flush, and `WorkerStopping` flushes unconditionally. A failing job's event is
therefore at most one loop iteration past the interval away from the ingest — not, as it would otherwise
be, waiting for `queue:restart`. Lower `ANVIL_FLUSH_INTERVAL_MS` for a chattier worker; raise it to batch
more aggressively.

## Configuration

```bash
php artisan vendor:publish --tag=anvil-feed-config
```

Publishing is optional — `.env` alone configures everything. Every key and its default is documented in
[`config/anvil-feed.php`](config/anvil-feed.php).

Three are worth knowing about:

- `ignored_request_paths` (default `['/api/feed/**']`) — exceptions raised while serving these paths are
  never reported. That is the loop guard for an application that is itself an ingest. Both the full path
  and the path relative to the application's mount point are matched, so an application served from
  `https://host/app/` needs no prefix in the pattern; a pattern of your own may use either spelling.
- `flush_interval_ms` (default 2000) — the cadence above.
- `transport` (default `direct`) — the bundled transport enforces the timeouts and refuses to follow a
  redirect, so the ingest token cannot be carried to whatever answered. `psr` uses the PSR-18 client bound
  in your container, and those two promises then become yours: `allow_redirects => false` and a timeout at
  or below `request_timeout_ms`.

Binding `AnvilFeed\Transport\Transport` in the container overrides the transport choice entirely.

## Filtering and enrichment

```php
app(AnvilFeed\Client::class)->beforeSend(function (array $event): ?array {
    $event['tags']['tenant'] = tenant()->slug;

    return $event;   // or null to drop it
});
```

The payload arrives already scrubbed and goes through the scrubber again on the way out, so anything the
hook adds meets the denylist like everything else. The SDK's own fields (`event_id`, `timestamp`,
`service`, `environment`, `title`, the stack) are restored if the hook changes them. A hook that throws
costs the modification, not the event.

## Running the tests

```bash
cd php/anvil-feed-laravel && composer install && vendor/bin/phpunit > /tmp/laravel.log 2>&1
```

Or, for both PHP packages at once, from `php/`: `composer install && vendor/bin/phpunit`.

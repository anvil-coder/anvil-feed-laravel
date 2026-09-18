# anvil-feed-laravel

The Laravel integration for the [Anvil Feed](../anvil-feed-php) SDK. An unhandled exception becomes one
event in the Anvil cockpit; the diff your application takes on is one line in `bootstrap/app.php` and a
token in `.env`.

Nothing is sent inside the request path. The events queue in memory and go out after the response
(`terminating`), bounded by `request_timeout_ms`, so an ingest that is slow, rate limiting or simply gone
cannot add a millisecond to your p99.

## Fifteen minutes, start to finish

**1. Install.**

```bash
composer require anvil-coder/anvil-feed-laravel
```

The provider is auto-discovered. There is nothing to add to `config/app.php`.

**2. Configure.** In `.env`:

```dotenv
ANVIL_INGEST_TOKEN=anv_srv_…        # from the cockpit: Settings → Ingest tokens
# optional — these default to config('app.name') and config('app.env')
ANVIL_SERVICE=invoicing-api
ANVIL_ENVIRONMENT=production
```

The ingest binds on a lower-case, dash-separated environment. `APP_ENV=Prod_EU` is repaired to `prod-eu`
and sent that way, with one line in your log saying so; a name that cannot be repaired switches sending
off rather than reporting into the wrong project.

**3. Hook up the exception handler.** In `bootstrap/app.php`:

```php
->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions) {
    AnvilFeed\Laravel\Integration::handles($exceptions);
})
```

That is the whole integration. The callback returns nothing, so Laravel keeps logging the exception
exactly as it does today.

**4. Verify.**

```bash
php artisan anvil-feed:test
```

It prints the endpoint, the service, the environment, the event id — and then the ingest's own verdict:

| verdict | what it means | what to do |
|---|---|---|
| `accepted` | the ingest stored the event | search the cockpit inbox for the event id |
| `rejected (no_binding)` | the token is valid; nothing is bound to it for this service/environment | connect the environment in the cockpit |
| `rejected (unauthorized)` | 401 — the token is unknown, revoked, or another organisation's | issue a new token |
| `failed transiently` | it never arrived; the event is still in the queue | check the endpoint and the log line beside it |
| `disabled` | the SDK is switched off — usually a missing `ANVIL_INGEST_TOKEN` | set the token |

**5. Throw something on purpose** — a route that raises `new RuntimeException('anvil smoke test')` — and
watch it appear. That is the fifteen minutes.

## What an event carries

- The exception, its stack, and up to 20 lines of source around each in-app frame.
- The request it happened in: method, full URL, and the status code where the exception already knows one.
  The route name travels as the tag `route`.
- The authenticated user's **id**, never a name or an address.
- `service`, `environment`, `release` (the deployed commit, read from `.git` without running `git`).

Nine denylisted terms (`password`, `token`, `secret`, `authorization`, `api_key`, `cookie`, `email`,
`credit_card`, `iban`) are redacted in keys, in text and in URLs before anything is queued.

## Queue workers

A job that exhausts its attempts is reported through Laravel's `JobFailed` event and tagged `queue`,
`job` and `connection`. Laravel reports the same throwable a second time through the exception handler;
the package reports each throwable once.

There is no response to terminate after in a worker, so the flush rides `register_shutdown_function`.

## Configuration

```bash
php artisan vendor:publish --tag=anvil-feed-config
```

Publishing is optional — `.env` alone configures everything. Every key and its default is documented in
[`config/anvil-feed.php`](config/anvil-feed.php).

Two of them are worth knowing about:

- `ignored_request_paths` (default `['/api/feed/**']`) — exceptions raised while serving these paths are
  never reported. That is the loop guard for an application that is itself an ingest.
- `transport` (default `direct`) — the bundled transport enforces the timeouts and refuses to follow a
  redirect, so the ingest token cannot be carried to whatever answered. `psr` uses the PSR-18 client bound
  in your container, and those two promises then become yours: `allow_redirects => false` and a timeout at
  or below `request_timeout_ms`.

Binding `AnvilFeed\Transport\Transport` in the container overrides both.

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

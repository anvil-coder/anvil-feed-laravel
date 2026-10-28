<?php

declare(strict_types=1);

/**
 * Every key here is the shared configuration name of the Anvil Feed SDKs — spelled identically in the PHP
 * core, in the Java starter and in conformance/config-defaults.json. A knob that exists in one language
 * only is a semantic drift, not a convenience.
 *
 * Publish this file with:
 *
 *   php artisan vendor:publish --tag=anvil-feed-config
 *
 * You do not have to. Without it the package's own copy is used and .env alone configures the SDK.
 */
return [
    // The default is the SDK's own constant rather than a second copy of the URL. An EMPTY variable
    // (`ANVIL_INGEST_ENDPOINT=` left in a .env) is not an absent one and env() returns '' for it, so the
    // repair to the default happens in AnvilFeedServiceProvider and in Config, not here.
    'endpoint' => env('ANVIL_INGEST_ENDPOINT', AnvilFeed\Config::DEFAULT_ENDPOINT),
    'token' => env('ANVIL_INGEST_TOKEN'),

    // Left null on purpose. AnvilFeedServiceProvider fills them from config('app.name') and
    // config('app.env'), which is the value the application actually runs with — env('APP_ENV') is not,
    // as soon as a config file or a test sets it. The ingest binds on a lower-case, dash-separated
    // environment, and Config repairs what it can: APP_ENV=Prod_EU arrives at the ingest as `prod-eu`.
    // A name it cannot repair disables sending, with one line in the log saying so.
    'service' => env('ANVIL_SERVICE'),
    'environment' => env('ANVIL_ENVIRONMENT'),

    // Falls back to the commit of the deployed checkout (read from .git without running git), then to a
    // REVISION or .release file in the application root, then to the releases/<id> directory name of a
    // zero-downtime deploy. `php artisan anvil-feed:test` prints which one answered.
    'release' => env('ANVIL_RELEASE'),

    'max_queue_size' => (int) env('ANVIL_MAX_QUEUE_SIZE', 1000),
    // How often a process that has no response to terminate after sends what it has queued: a queue
    // worker (on every loop iteration, and when a job ends) and a long-running Artisan command. A web
    // request flushes once, after the response, whatever this says.
    'flush_interval_ms' => (int) env('ANVIL_FLUSH_INTERVAL_MS', 2000),
    'batch_size' => (int) env('ANVIL_BATCH_SIZE', 50),
    'connect_timeout_ms' => (int) env('ANVIL_CONNECT_TIMEOUT_MS', 1000),
    'request_timeout_ms' => (int) env('ANVIL_REQUEST_TIMEOUT_MS', 2000),

    'max_frames' => (int) env('ANVIL_MAX_FRAMES', 50),
    'context_lines' => (int) env('ANVIL_CONTEXT_LINES', 20),
    'max_context_line_chars' => (int) env('ANVIL_MAX_CONTEXT_LINE_CHARS', 400),

    // Replaces the default list, it does not extend it. The nine defaults are FR-5 a.
    'denylist' => AnvilFeed\Config::DEFAULT_DENYLIST,

    'enabled' => (bool) env('ANVIL_ENABLED', true),
    'fingerprint_cap' => (int) env('ANVIL_FINGERPRINT_CAP', 10),
    'fingerprint_window_ms' => (int) env('ANVIL_FINGERPRINT_WINDOW_MS', 60000),

    // Exceptions raised while serving one of these paths are never reported: that is the loop guard for an
    // application that is itself an Anvil ingest. `*` stops at a segment boundary, `**` crosses it. Both
    // the full path and the path relative to the application's mount point are matched, so an application
    // served from https://host/app/ needs no prefix here.
    'ignored_request_paths' => ['/api/feed/**'],

    /*
     * 'direct' (the default) uses the SDK's own transport: one file, no dependency, and it enforces
     * connect_timeout_ms, request_timeout_ms and "never follow a redirect" itself — which matters, because
     * a redirect would carry the ingest token to whatever answered.
     *
     * 'psr' uses the PSR-18 client bound in this application's container. PSR-18 has no timeout knob and
     * no redirect knob, so those two promises become YOURS: configure the client with
     * `allow_redirects => false` and a timeout at or below request_timeout_ms (Guzzle spells them
     * `allow_redirects` and `timeout`). If 'psr' is configured and no PSR-18 client is bound, the SDK
     * stays on 'direct' and says so once.
     *
     * Binding AnvilFeed\Transport\Transport yourself overrides both.
     */
    'transport' => env('ANVIL_TRANSPORT', 'direct'),
];

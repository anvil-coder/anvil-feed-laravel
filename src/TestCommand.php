<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel;

use AnvilFeed\Client;
use AnvilFeed\Config;
use AnvilFeed\Transport\Response;
use Illuminate\Console\Command;

/**
 * FR-8: install, configure, run this, see the event. That is the fifteen minutes.
 *
 * What it prints is a VERDICT, not a confirmation that something was sent. "Sent" is the proxy that makes
 * a developer wait ten minutes for an event the ingest rejected with `no_binding` half a second after it
 * arrived. Four answers are possible and all four are named here:
 *
 *   accepted            the ingest stored it; it is in the cockpit inbox
 *   rejected            it arrived and was refused, with the ingest's own reason_code
 *   failed transiently  it did not arrive; the event stays in the queue and the log says why
 *   disabled            nothing was sent, because the SDK is switched off — a missing token, usually
 */
final class TestCommand extends Command
{
    protected $signature = 'anvil-feed:test';

    protected $description = 'Send one test event to the Anvil Feed ingest and report what came back.';

    public function handle(Config $config, Client $client, VerdictTransport $transport): int
    {
        $this->line('endpoint:    ' . $config->eventsUrl());
        $this->line('service:     ' . $config->service);
        $this->line('environment: ' . $config->environment);
        $this->line('release:     ' . ($config->release ?? '(none — not a git checkout, and ANVIL_RELEASE is unset)'));
        $this->line('token:       ' . ($config->token === null
            ? 'MISSING — set ANVIL_INGEST_TOKEN'
            : substr($config->token, 0, 16) . '…'));

        if (!$config->enabled) {
            $this->error('verdict:     disabled');
            $this->line($config->token === null
                ? '             no ingest token (ANVIL_INGEST_TOKEN). Nothing was sent.'
                : '             the configuration above was refused; the log line beside it says which '
                    . 'value. Nothing was sent.');

            return self::FAILURE;
        }

        $id = $client->captureTestEvent();
        if ($id === null) {
            $this->error('verdict:     nothing was queued');
            $this->line('             The log says why. A 401 earlier in this process stops the SDK for '
                . 'its lifetime.');

            return self::FAILURE;
        }
        $this->line('event_id:    ' . $id);

        $before = $transport->last();
        $client->flush();
        $answer = $transport->last();

        if ($client->sender()->isStopped()) {
            $this->error('verdict:     rejected (unauthorized)');
            $this->line('             The ingest answered 401: the token is unknown, revoked, or belongs '
                . 'to another organisation.');

            return self::FAILURE;
        }
        if ($answer === null || $answer === $before) {
            $this->warn('verdict:     failed transiently');
            $this->line('             Nothing reached the ingest. The sender is paused after an earlier '
                . '429 or 503, and the event is still in the queue.');

            return self::FAILURE;
        }

        [$verdict, $label, $details] = $this->readVerdict($answer, $id);

        match ($verdict) {
            'accepted' => $this->info('verdict:     accepted'),
            'rejected' => $this->error('verdict:     rejected (' . $label . ')'),
            default => $this->warn('verdict:     failed transiently'),
        };
        foreach ($details as $line) {
            $this->line('             ' . $line);
        }

        if ($verdict === 'accepted') {
            $this->line('Search the Anvil cockpit inbox for ' . $id . '. It is there within a minute.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * The ingest's own answer, read the way docs/anvil-feed-ingest.md defines it: a 202 carries a verdict
     * per event in `accepted` or `rejected`, and a rejection always names a `reason_code`.
     *
     * @return array{0: 'accepted'|'rejected'|'transient', 1: string, 2: list<string>}
     */
    private function readVerdict(Response $answer, string $id): array
    {
        $body = json_decode($answer->body, true);
        $body = is_array($body) ? $body : [];
        $rejection = $this->rejectionFor($body, $id);

        if ($answer->status === 202) {
            $accepted = is_array($body['accepted'] ?? null) ? $body['accepted'] : [];
            if (in_array($id, $accepted, true)) {
                return ['accepted', 'accepted', ['The ingest stored the event.']];
            }
            if ($rejection !== null) {
                return ['rejected', $this->reasonCode($rejection), $this->explain($rejection)];
            }

            return ['transient', 'no verdict', ['The ingest answered 202 without a verdict for this '
                . 'event. The SDK sends it once more and then drops it; the configuration is not at '
                . 'fault.']];
        }

        if ($answer->transportFailure) {
            return ['transient', 'unreachable', [$answer->failureReason,
                'The event stays in the queue and goes out with the next flush.']];
        }

        return match (true) {
            $answer->status === 400 => ['rejected', $this->reasonCode($rejection ?? []),
                $this->explain($rejection ?? [])],
            $answer->status === 401 => ['rejected', 'unauthorized', ['The ingest answered 401: the token '
                . 'is unknown, revoked, or belongs to another organisation.']],
            $answer->status === 403 => ['rejected', 'forbidden', ['The ingest answered 403. A proxy in '
                . 'front of this application is adding an Origin header to a server-token request; fix '
                . 'the proxy, do not rotate the token.']],
            $answer->status === 413 => ['rejected', 'payload_too_large', $this->explain($rejection ?? [])],
            $answer->status === 429 => ['transient', 'rate_limited', ['The ingest is rate limiting this '
                . 'token. The event stays in the queue and goes out after Retry-After.']],
            $answer->status >= 500 => ['transient', 'ingest_unavailable', ['The ingest answered '
                . $answer->status . '. The event stays in the queue.']],
            default => ['rejected', 'http_' . $answer->status, $this->explain($rejection ?? [])],
        };
    }

    /**
     * @param array<mixed> $body
     * @return array<string, mixed>|null
     */
    private function rejectionFor(array $body, string $id): ?array
    {
        $rejected = is_array($body['rejected'] ?? null) ? $body['rejected'] : [];
        foreach ($rejected as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            // event_id null is the ingest's answer when it could not read the body far enough to name one.
            $named = $entry['event_id'] ?? null;
            if ($named === $id || $named === null) {
                return $entry;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $rejection */
    private function reasonCode(array $rejection): string
    {
        return is_string($rejection['reason_code'] ?? null) ? $rejection['reason_code'] : 'unknown';
    }

    /**
     * What the reason code means for the person who just ran this, in the words they need to act on.
     *
     * @param array<string, mixed> $rejection
     * @return list<string>
     */
    private function explain(array $rejection): array
    {
        $lines = [];
        $message = is_string($rejection['message'] ?? null) ? $rejection['message'] : '';
        if ($message !== '') {
            $lines[] = $message;
        }

        $hint = match ($this->reasonCode($rejection)) {
            'no_binding' => 'Connect this environment in the Anvil cockpit: the token is valid, and '
                . 'nothing is bound to it for the service and environment printed above.',
            'binding_disabled' => 'The binding exists in the cockpit and is switched off. Switch it on.',
            'schema_violation' => 'This is an SDK bug, not a configuration mistake. Report it with the '
                . 'message above.',
            default => '',
        };
        if ($hint !== '') {
            $lines[] = $hint;
        }

        return $lines === [] ? ['The ingest gave no reason code.'] : $lines;
    }
}

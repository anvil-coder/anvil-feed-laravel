<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests\Support;

use AnvilFeed\Transport\Response;
use AnvilFeed\Transport\Transport;

/**
 * The ingest's other 202: the body arrived, was read, and every event in it was refused by name.
 *
 * It answers with the ids it was actually posted, because a rejection that names a different id is a
 * different case (the SDK retries that one once and then drops it) and the command must not read one as
 * the other.
 */
final class RejectingTransport implements Transport
{
    public function __construct(
        private readonly string $reasonCode = 'no_binding',
        private readonly string $message = "no binding for environment 'testing'",
    ) {
    }

    public function post(string $url, string $body, ?int $timeoutMs = null): Response
    {
        $decoded = json_decode($body, true);
        $events = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];

        $rejected = [];
        foreach ($events as $event) {
            if (is_array($event) && is_string($event['event_id'] ?? null)) {
                $rejected[] = [
                    'event_id' => $event['event_id'],
                    'reason_code' => $this->reasonCode,
                    'message' => $this->message,
                ];
            }
        }

        return new Response(202, (string) json_encode(['accepted' => [], 'rejected' => $rejected]));
    }
}

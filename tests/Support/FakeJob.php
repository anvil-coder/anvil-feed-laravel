<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests\Support;

use Illuminate\Contracts\Queue\Job;

/**
 * A queue job that is only ever asked what it is called and which queue it came off.
 *
 * A real one would need a connection and a driver; JobFailed carries the contract, and the contract is
 * all the listener reads.
 */
final class FakeJob implements Job
{
    public function __construct(
        private readonly string $queue = 'queue-worker',
        private readonly string $name = 'App\\Jobs\\SendInvoice',
        private readonly string $connection = 'redis',
    ) {
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function resolveName(): string
    {
        return $this->name;
    }

    public function resolveQueuedJobClass(): string
    {
        return $this->name;
    }

    public function getConnectionName(): string
    {
        return $this->connection;
    }

    public function uuid(): ?string
    {
        return 'b6d8e6f0-0000-4000-8000-000000000000';
    }

    public function getJobId(): string
    {
        return 'job-1';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [];
    }

    public function getRawBody(): string
    {
        return '{}';
    }

    public function fire(): void
    {
    }

    public function release($delay = 0): void
    {
    }

    public function isReleased(): bool
    {
        return false;
    }

    public function delete(): void
    {
    }

    public function isDeleted(): bool
    {
        return false;
    }

    public function isDeletedOrReleased(): bool
    {
        return false;
    }

    public function attempts(): int
    {
        return 3;
    }

    public function hasFailed(): bool
    {
        return true;
    }

    public function markAsFailed(): void
    {
    }

    public function fail($e = null): void
    {
    }

    public function maxTries(): ?int
    {
        return 3;
    }

    public function maxExceptions(): ?int
    {
        return null;
    }

    public function backoff(): ?int
    {
        return null;
    }

    public function retryUntil(): ?int
    {
        return null;
    }

    public function timeout(): ?int
    {
        return null;
    }
}

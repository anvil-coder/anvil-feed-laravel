<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;

/**
 * A guard that counts how often it was asked to FIND a user, and fails when it is.
 *
 * It stands for the case the request-path rule exists for: an exception thrown because the database is
 * down, on a request where authentication has not run yet. Asking this guard for a user is a session read
 * and a query against the dead database, inside the error path. The counter is what proves the SDK never
 * asks.
 */
final class ExplodingGuard implements Guard
{
    /** Calls to user() — the SDK's path. */
    public int $userCalls = 0;

    /** Calls to id() — Laravel's own path: Handler::context() puts Auth::id() in its log context. */
    public int $idCalls = 0;

    public function __construct(private readonly bool $hasUser = false)
    {
    }

    public function hasUser(): bool
    {
        return $this->hasUser;
    }

    public function user(): ?Authenticatable
    {
        $this->userCalls++;

        throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    }

    public function check(): bool
    {
        return $this->hasUser;
    }

    public function guest(): bool
    {
        return !$this->hasUser;
    }

    public function id(): null
    {
        $this->idCalls++;

        throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    }

    /** @param array<string, mixed> $credentials */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function setUser(Authenticatable $user): void
    {
    }
}

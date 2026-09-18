<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/** An authenticated user with an id and a name, so a test can prove only the id travels. */
final class FakeUser implements Authenticatable
{
    public function __construct(
        private readonly string $id = 'u-42',
        public readonly string $name = 'Ada Lovelace',
        public readonly string $email = 'ada@example.com',
    ) {
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}

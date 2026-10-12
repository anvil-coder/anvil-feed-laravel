<?php

declare(strict_types=1);

namespace AnvilFeed\Laravel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each package directory is the root of a read-only mirror that Packagist reads. What Packagist reads has
 * to be publishable on its own: no path repositories (Composer ignores them in a dependency and warns in
 * every consumer), a license file beside the manifest, and the Laravel majors the spec names — 12 and 13,
 * nothing older.
 */
final class PublishableManifestTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function packageDirectories(): iterable
    {
        yield 'anvil-feed-php' => [dirname(__DIR__, 2) . '/anvil-feed-php'];
        yield 'anvil-feed-laravel' => [dirname(__DIR__, 2) . '/anvil-feed-laravel'];
    }

    #[DataProvider('packageDirectories')]
    public function testThePackageManifestCarriesNoRepositories(string $directory): void
    {
        $manifest = self::manifest($directory);
        self::assertArrayNotHasKey('repositories', $manifest, "$directory/composer.json must not declare repositories");
    }

    #[DataProvider('packageDirectories')]
    public function testALicenseFileSitsBesideTheManifest(string $directory): void
    {
        self::assertFileExists($directory . '/LICENSE');
        self::assertStringContainsString('MIT License', (string) file_get_contents($directory . '/LICENSE'));
        self::assertSame('MIT', self::manifest($directory)['license']);
    }

    public function testTheLaravelPackageSupportsLaravelTwelveAndThirteenOnly(): void
    {
        $require = self::manifest(dirname(__DIR__))['require'];
        foreach (['illuminate/console', 'illuminate/contracts', 'illuminate/support'] as $package) {
            self::assertSame('^12.0|^13.0', $require[$package], $package);
        }
        self::assertSame('>=8.3', $require['php']);
    }

    public function testTheJavaTreeHasALicenseFile(): void
    {
        self::assertFileExists(dirname(__DIR__, 3) . '/java/LICENSE');
    }

    /** @return array<string, mixed> */
    private static function manifest(string $directory): array
    {
        return json_decode((string) file_get_contents($directory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}

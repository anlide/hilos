<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth;

use Hilos\Auth\AuthenticatorName;
use Hilos\Constants\AppEnv;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AuthenticatorName}.
 */
final class AuthenticatorNameTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
        $this->previousAppEnv = getenv('APP_ENV');
        putenv('HILOS_WEBAUTHN_RP_NAME=Hilos');
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv('HILOS_WEBAUTHN_RP_NAME');
        $this->previousAppEnv === false
            ? putenv('APP_ENV')
            : putenv('APP_ENV=' . $this->previousAppEnv);
    }

    /**
     * @param ?AppEnv $env Environment case or null
     * @param string $expected Expected display name
     */
    #[DataProvider('composeCases')]
    public function testComposeAppendsEnvironmentOutsideProduction(?AppEnv $env, string $expected): void
    {
        self::assertSame($expected, AuthenticatorName::compose('Hilos', $env));
    }

    /**
     * @return iterable<string, array{?AppEnv, string}>
     */
    public static function composeCases(): iterable
    {
        yield 'prod' => [AppEnv::PROD, 'Hilos'];
        yield 'null unrecognized' => [null, 'Hilos'];
        yield 'local' => [AppEnv::LOCAL, 'Hilos (local)'];
        yield 'dev' => [AppEnv::DEV, 'Hilos (dev)'];
        yield 'test' => [AppEnv::TEST, 'Hilos (test)'];
        yield 'staging' => [AppEnv::STAGING, 'Hilos (staging)'];
    }

    /**
     * @param string $appEnv Value of APP_ENV
     * @param string $expected Expected display name
     */
    #[DataProvider('fromEnvCases')]
    public function testFromEnvResolvesFromEnvironmentConfiguration(string $appEnv, string $expected): void
    {
        putenv('APP_ENV=' . $appEnv);

        self::assertSame($expected, AuthenticatorName::fromEnv());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fromEnvCases(): iterable
    {
        yield 'prod' => ['prod', 'Hilos'];
        yield 'local' => ['local', 'Hilos (local)'];
        yield 'development' => ['development', 'Hilos (dev)'];
        yield 'qa unrecognized' => ['qa', 'Hilos'];
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Auth\ChatOAuthConfig;
use Demo\Chat\Environment\ChatEnvCatalog;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what the chat demo's OAuth wiring leaves standing per environment (HIL-671).
 *
 * The demo is the case the leaf was written about: it ships OAuth wired but its credentials
 * unset, so every provider resolves to an offline stub. On a stand that stub is the whole
 * point - the auth e2e signs in through it. On a production node it would be a sign-in that
 * verifies nothing, which is why the same wiring must come out empty there. Both halves are
 * asserted here because a project reads its enabled sign-in methods off this registry.
 */
final class ChatOAuthConfigTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
    }

    /**
     * With credentials unset, a production node ends up with no OAuth provider at all.
     *
     * The credentials are unset by simply not being set: the catalog declares
     * OAUTH_*_CLIENT_ID and OAUTH_*_CLIENT_SECRET with an empty default, which is
     * exactly the state an installation that never configured OAuth is in.
     */
    public function testProductionNodeEndsUpWithNoProviders(): void
    {
        self::setAppEnv('prod');

        self::assertSame([], ChatOAuthConfig::buildProviderRegistry()->keys());
    }

    /**
     * The same unset credentials on a dev node still raise a stub per configured provider.
     */
    public function testDevNodeKeepsAStubPerProvider(): void
    {
        self::setAppEnv('dev');

        self::assertSame(
            [OAuthProviderPreset::GITHUB->value, OAuthProviderPreset::GOOGLE->value],
            ChatOAuthConfig::buildProviderRegistry()->keys(),
        );
    }

    /**
     * Points the environment verdict at one APP_ENV value for the duration of a case.
     *
     * @param string $appEnv Value APP_ENV resolves to while the registry is built
     */
    private static function setAppEnv(string $appEnv): void
    {
        putenv('APP_ENV=' . $appEnv);
        Hilos::$env = new EnvAccessor(ChatEnvCatalog::class);
    }
}

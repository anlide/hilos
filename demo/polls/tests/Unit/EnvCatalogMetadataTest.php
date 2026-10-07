<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Unit;

use Demo\Polls\Constants\PollsEnvConstants;
use Demo\Polls\Environment\PollsEnvCatalog;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use PHPUnit\Framework\TestCase;

/**
 * Checks the effective catalog after this demo replaces framework entries.
 */
final class EnvCatalogMetadataTest extends TestCase
{
    public function testFrameworkKeyClassificationsSurviveOverrides(): void
    {
        $framework = new EnvAccessor(EnvCatalogStub::class);
        $demo = new EnvAccessor(PollsEnvCatalog::class);

        foreach (array_keys(EnvCatalogStub::getCatalog()) as $key) {
            $this->assertSame($framework->sensitiveFor($key), $demo->sensitiveFor($key), $key);
            $this->assertSame($framework->perNodeFor($key), $demo->perNodeFor($key), $key);
            $this->assertSame(
                $framework->visibleInAdminViewMode($key),
                $demo->visibleInAdminViewMode($key),
                $key,
            );
        }
    }

    public function testOAuthSecretsAreExplicitlySensitive(): void
    {
        $env = new EnvAccessor(PollsEnvCatalog::class);
        foreach ([
            PollsEnvConstants::OAUTH_STATE_SECRET,
            PollsEnvConstants::OAUTH_GITHUB_CLIENT_SECRET,
            PollsEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET,
        ] as $key) {
            $this->assertTrue($env->sensitiveFor($key), $key);
            $this->assertFalse($env->visibleInAdminViewMode($key), $key);
        }
        foreach ([
            PollsEnvConstants::OAUTH_GITHUB_CLIENT_ID,
            PollsEnvConstants::OAUTH_GOOGLE_CLIENT_ID,
            PollsEnvConstants::OAUTH_REDIRECT_URI,
        ] as $key) {
            $this->assertFalse($env->sensitiveFor($key), $key);
        }
    }
}

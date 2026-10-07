<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Constants\ChatEnvConstants;
use Demo\Chat\Environment\ChatEnvCatalog;
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
        $demo = new EnvAccessor(ChatEnvCatalog::class);

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
        $env = new EnvAccessor(ChatEnvCatalog::class);
        foreach ([
            ChatEnvConstants::OAUTH_STATE_SECRET,
            ChatEnvConstants::OAUTH_GITHUB_CLIENT_SECRET,
            ChatEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET,
        ] as $key) {
            $this->assertTrue($env->sensitiveFor($key), $key);
            $this->assertFalse($env->visibleInAdminViewMode($key), $key);
        }
        foreach ([
            ChatEnvConstants::OAUTH_GITHUB_CLIENT_ID,
            ChatEnvConstants::OAUTH_GOOGLE_CLIENT_ID,
            ChatEnvConstants::OAUTH_REDIRECT_URI,
        ] as $key) {
            $this->assertFalse($env->sensitiveFor($key), $key);
        }
    }
}

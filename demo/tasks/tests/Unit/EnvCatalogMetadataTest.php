<?php

declare(strict_types=1);

namespace Demo\Tasks\Tests\Unit;

use Demo\Tasks\Constants\TasksEnvConstants;
use Demo\Tasks\Environment\TasksEnvCatalog;
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
        $demo = new EnvAccessor(TasksEnvCatalog::class);

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
        $env = new EnvAccessor(TasksEnvCatalog::class);
        foreach ([
            TasksEnvConstants::OAUTH_STATE_SECRET,
            TasksEnvConstants::OAUTH_GITHUB_CLIENT_SECRET,
            TasksEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET,
        ] as $key) {
            $this->assertTrue($env->sensitiveFor($key), $key);
            $this->assertFalse($env->visibleInAdminViewMode($key), $key);
        }
        foreach ([
            TasksEnvConstants::OAUTH_GITHUB_CLIENT_ID,
            TasksEnvConstants::OAUTH_GOOGLE_CLIENT_ID,
            TasksEnvConstants::OAUTH_REDIRECT_URI,
        ] as $key) {
            $this->assertFalse($env->sensitiveFor($key), $key);
        }
    }
}

<?php

declare(strict_types=1);

namespace Demo\Tasks\Tests\Unit;

use Demo\Tasks\Constants\TasksEnvConstants;
use Demo\Tasks\Environment\TasksEnvCatalog;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;
use PHPUnit\Framework\TestCase;

/**
 * Checks the effective catalog after this demo replaces framework entries.
 */
final class EnvCatalogMetadataTest extends TestCase
{
    /** Published storage is a nonsecret string override with an empty default. */
    public function testPublishedDirectoryMetadata(): void
    {
        $env = new EnvAccessor(TasksEnvCatalog::class);
        self::assertSame([
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
            EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => '',
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => false,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
        ], TasksEnvCatalog::getCatalog()[TasksEnvConstants::TASKS_FILES_PUBLISHED_DIR]);
        self::assertFalse($env->sensitiveFor(TasksEnvConstants::TASKS_FILES_PUBLISHED_DIR));
        self::assertFalse($env->perNodeFor(TasksEnvConstants::TASKS_FILES_PUBLISHED_DIR));
    }

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
            TasksEnvConstants::TASKS_FILES_PUBLISHED_DIR,
        ] as $key) {
            $this->assertFalse($env->sensitiveFor($key), $key);
        }
    }
}

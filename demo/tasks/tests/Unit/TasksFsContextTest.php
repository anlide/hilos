<?php

declare(strict_types=1);

namespace Demo\Tasks\Tests\Unit;

use Demo\Tasks\Constants\TasksEnvConstants;
use Demo\Tasks\Environment\TasksEnvCatalog;
use Demo\Tasks\Fs\TasksFsContext;
use Demo\Tasks\Hilos;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the tasks filesystem context (HIL-1240).
 *
 * Files, uploads and exports belong to the cluster; the analytics journal belongs to each node.
 */
final class TasksFsContextTest extends TestCase
{
    private ?EnvAccessor $previousEnv;

    private string|false $previousPublishedDir;

    /** Isolates the process override and typed environment without creating storage. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousPublishedDir = getenv(TasksEnvConstants::TASKS_FILES_PUBLISHED_DIR);
        putenv(TasksEnvConstants::TASKS_FILES_PUBLISHED_DIR);
        Hilos::$env = new EnvAccessor(TasksEnvCatalog::class);
    }

    /** Restores the environment seen by the remaining unit tests. */
    protected function tearDown(): void
    {
        if ($this->previousPublishedDir === false) {
            putenv(TasksEnvConstants::TASKS_FILES_PUBLISHED_DIR);
        } else {
            putenv(TasksEnvConstants::TASKS_FILES_PUBLISHED_DIR . '=' . $this->previousPublishedDir);
        }
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    /** The shared directories and the node journal preserve their declared scopes. */
    public function testTheExportDirectoriesBelongToTheCluster(): void
    {
        $context = new TasksFsContext();
        $context->configure();

        self::assertSame([
            FsContext::FILES,
            FsContext::ANALYTICS_JOURNAL,
            FsContext::DATA_EXPORT,
            FsContext::LEGAL_EXPORT,
        ], array_keys($context->getDirectories()));
        self::assertSame(DirectoryScope::CLUSTER, $context->getTmp()->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::FILES)->getScope());
        self::assertSame(DirectoryScope::NODE, $context->getDirectory(FsContext::ANALYTICS_JOURNAL)->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::DATA_EXPORT)->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::LEGAL_EXPORT)->getScope());
        self::assertSame([], $context->declarationErrors());
        $dataRoot = dirname(__DIR__, 2) . '/data';
        self::assertSame($dataRoot . '/analytics_journal', $context->getDirectory(FsContext::ANALYTICS_JOURNAL)->getPath());
        self::assertSame($dataRoot . '/data_export', $context->getDirectory(FsContext::DATA_EXPORT)->getPath());
        self::assertSame($dataRoot . '/legal_export', $context->getDirectory(FsContext::LEGAL_EXPORT)->getPath());
        self::assertSame($dataRoot . '/files/published', $context->getDirectory(FsContext::FILES)->getPath());
        self::assertSame($dataRoot . '/files/tmp', $context->getTmp()->getPath());
    }

    /** An explicitly empty override retains the same project-relative default. */
    public function testEmptyPublishedDirectoryUsesTheDefault(): void
    {
        putenv(TasksEnvConstants::TASKS_FILES_PUBLISHED_DIR . '=');
        $context = new TasksFsContext();
        $context->configure();

        self::assertSame('', Hilos::$env[TasksEnvConstants::TASKS_FILES_PUBLISHED_DIR]->string());
        self::assertSame(dirname(__DIR__, 2) . '/data/files/published', $context->getDirectory(FsContext::FILES)->getPath());
        self::assertSame([], $context->declarationErrors());
    }

    /** The published-directory override leaves uploads in the shared project tmp directory. */
    public function testPublishedDirectoryCanBeOverridden(): void
    {
        putenv(TasksEnvConstants::TASKS_FILES_PUBLISHED_DIR . '=/tmp/tasks-profile-photo-published');
        $context = new TasksFsContext();
        $context->configure();

        self::assertSame('/tmp/tasks-profile-photo-published', $context->getDirectory(FsContext::FILES)->getPath());
        self::assertSame(dirname(__DIR__, 2) . '/data/files/tmp', $context->getTmp()->getPath());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::FILES)->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getTmp()->getScope());
        self::assertSame([], $context->declarationErrors());
    }
}

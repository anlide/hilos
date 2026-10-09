<?php

declare(strict_types=1);

namespace Demo\Tasks\Tests\Unit;

use Demo\Tasks\Fs\TasksFsContext;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the tasks filesystem context (HIL-1240).
 *
 * The account and acceptance exports belong to the cluster; the analytics journal belongs to each node.
 */
final class TasksFsContextTest extends TestCase
{
    public function testTheExportDirectoriesBelongToTheCluster(): void
    {
        $context = new TasksFsContext();
        $context->configure();

        self::assertSame([FsContext::ANALYTICS_JOURNAL, FsContext::DATA_EXPORT, FsContext::LEGAL_EXPORT], array_keys($context->getDirectories()));
        self::assertSame(DirectoryScope::NODE, $context->getDirectory(FsContext::ANALYTICS_JOURNAL)->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::DATA_EXPORT)->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::LEGAL_EXPORT)->getScope());
        self::assertSame([], $context->declarationErrors());
    }
}

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
 * The two directories the project registers keep the account exports and the administrators'
 * exports of acceptance records (HIL-1234); the agents writing them may move between nodes, so
 * both are the cluster's and the start accepts the declaration.
 */
final class TasksFsContextTest extends TestCase
{
    public function testTheExportDirectoriesBelongToTheCluster(): void
    {
        $context = new TasksFsContext();
        $context->configure();

        self::assertSame([FsContext::DATA_EXPORT, FsContext::LEGAL_EXPORT], array_keys($context->getDirectories()));
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::DATA_EXPORT)->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::LEGAL_EXPORT)->getScope());
        self::assertSame([], $context->declarationErrors());
    }
}

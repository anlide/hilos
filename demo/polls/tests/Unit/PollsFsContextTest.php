<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Unit;

use Demo\Polls\Fs\PollsFsContext;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the polls filesystem context (HIL-1240).
 *
 * The account and acceptance exports belong to the cluster; the analytics journal belongs to each node.
 */
final class PollsFsContextTest extends TestCase
{
    public function testTheExportDirectoriesBelongToTheCluster(): void
    {
        $context = new PollsFsContext();
        $context->configure();

        self::assertSame([FsContext::ANALYTICS_JOURNAL, FsContext::DATA_EXPORT, FsContext::LEGAL_EXPORT], array_keys($context->getDirectories()));
        self::assertSame(DirectoryScope::NODE, $context->getDirectory(FsContext::ANALYTICS_JOURNAL)->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::DATA_EXPORT)->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::LEGAL_EXPORT)->getScope());
        self::assertSame([], $context->declarationErrors());
    }
}

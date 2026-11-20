<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Tests\Unit;

use Demo\HilosWebsite\Fs\HilosWebsiteFsContext;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the hilos-website filesystem context (HIL-1240).
 *
 * The directories the project registers keeps the account exports, and the export agent may
 * move between nodes, so the directory is the cluster's and the start accepts the declaration.
 */
final class HilosWebsiteFsContextTest extends TestCase
{
    public function testTheDataExportDirectoryBelongsToTheCluster(): void
    {
        $context = new HilosWebsiteFsContext();
        $context->configure();

        self::assertSame(
            [FsContext::ANALYTICS_JOURNAL, FsContext::DATA_EXPORT],
            array_keys($context->getDirectories()),
        );
        self::assertSame(DirectoryScope::NODE, $context->getDirectory(FsContext::ANALYTICS_JOURNAL)->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::DATA_EXPORT)->getScope());
        self::assertSame([], $context->declarationErrors());
    }

    public function testStatusIsNotRegistered(): void
    {
        $context = new HilosWebsiteFsContext();
        $context->configure();

        self::assertFalse($context->hasDirectory(FsContext::STATUS));
        self::assertSame(
            [FsContext::DATA_EXPORT => dirname(__DIR__, 2) . '/data/data_export'],
            $context->clusterDirectories(),
        );
    }
}

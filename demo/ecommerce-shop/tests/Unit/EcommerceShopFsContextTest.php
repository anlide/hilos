<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Fs\EcommerceShopFsContext;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ecommerce-shop filesystem context (HIL-1240).
 *
 * The directories the project registers keeps the account exports, and the export agent may
 * move between nodes, so the directory is the cluster's and the start accepts the declaration.
 * The published catalog pictures and the uploads under way are the cluster's for the same reason.
 */
final class EcommerceShopFsContextTest extends TestCase
{
    public function testTheDataExportDirectoryBelongsToTheCluster(): void
    {
        $context = new EcommerceShopFsContext();
        $context->configure();

        self::assertSame(
            [FsContext::ANALYTICS_JOURNAL, FsContext::DATA_EXPORT, FsContext::STATUS, FsContext::FILES],
            array_keys($context->getDirectories()),
        );
        self::assertSame(DirectoryScope::NODE, $context->getDirectory(FsContext::ANALYTICS_JOURNAL)->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::DATA_EXPORT)->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::FILES)->getScope());
        self::assertSame(DirectoryScope::CLUSTER, $context->getTmp()->getScope());
        self::assertSame([], $context->declarationErrors());
        $dataRoot = dirname(__DIR__, 2) . '/data';
        self::assertSame($dataRoot . '/files/published', $context->getDirectory(FsContext::FILES)->getPath());
        self::assertSame($dataRoot . '/files/tmp', $context->getTmp()->getPath());
    }

    public function testStatusUsesItsOwnSharedDataDirectory(): void
    {
        $context = new EcommerceShopFsContext();
        $context->configure();

        self::assertTrue($context->hasDirectory(FsContext::STATUS));
        self::assertSame(dirname(__DIR__, 2) . '/data/status', $context->status->getPath());
        self::assertSame(DirectoryScope::CLUSTER, $context->status->getScope());
        self::assertSame(dirname(__DIR__, 2) . '/data/status', $context->clusterDirectories()[FsContext::STATUS]);
    }
}

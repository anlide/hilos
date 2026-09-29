<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Tests\Unit;

use Demo\BinanceBtcTracker\Fs\BinanceBtcTrackerFsContext;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the binance-btc-tracker filesystem context (HIL-1240).
 *
 * The one directory the project registers keeps the account exports, and the export agent may
 * move between nodes, so the directory is the cluster's and the start accepts the declaration.
 */
final class BinanceBtcTrackerFsContextTest extends TestCase
{
    public function testTheDataExportDirectoryBelongsToTheCluster(): void
    {
        $context = new BinanceBtcTrackerFsContext();
        $context->configure();

        self::assertSame([FsContext::DATA_EXPORT], array_keys($context->getDirectories()));
        self::assertSame(DirectoryScope::CLUSTER, $context->getDirectory(FsContext::DATA_EXPORT)->getScope());
        self::assertSame([], $context->declarationErrors());
    }
}

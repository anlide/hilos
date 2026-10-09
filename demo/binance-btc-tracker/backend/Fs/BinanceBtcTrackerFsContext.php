<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Fs;

use Demo\BinanceBtcTracker\Hilos;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;

/** Shared account exports and the node-local analytics journal. */
final class BinanceBtcTrackerFsContext extends FsContext
{
    /** Registers shared account exports and node-local analytics journal storage. */
    public function configure(): void
    {
        $this->registerDirectory(
            FsContext::ANALYTICS_JOURNAL,
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/analytics_journal',
            DirectoryScope::NODE,
        );
        $this->registerDirectory(
            FsContext::DATA_EXPORT,
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/data_export',
            DirectoryScope::CLUSTER,
        );
    }
}

<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Fs;

use Demo\BinanceBtcTracker\Hilos;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;

/** Shared archive directory of the project's account exports. */
final class BinanceBtcTrackerFsContext extends FsContext
{
    /** Registers storage shared by the project's nodes and their web servers. */
    public function configure(): void
    {
        $this->registerDirectory(
            FsContext::DATA_EXPORT,
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/data_export',
            DirectoryScope::CLUSTER,
        );
    }
}

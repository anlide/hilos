<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Fs;

use Demo\OnlineTesting\Hilos;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;

/** Shared account exports and public Status files, and the node-local analytics journal. */
final class OnlineTestingFsContext extends FsContext
{
    /** Registers shared account exports and Status files and node-local analytics journal storage. */
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
        $this->registerDirectory(
            FsContext::STATUS,
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/status',
            DirectoryScope::CLUSTER,
        );
    }
}

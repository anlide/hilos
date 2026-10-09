<?php

declare(strict_types=1);

namespace Demo\Polls\Fs;

use Demo\Polls\Hilos;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;

/** Shared export directories and the node-local analytics journal. */
final class PollsFsContext extends FsContext
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
        $this->registerDirectory(
            FsContext::LEGAL_EXPORT,
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/legal_export',
            DirectoryScope::CLUSTER,
        );
    }
}

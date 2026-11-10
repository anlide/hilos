<?php

declare(strict_types=1);

namespace Demo\Pmd\Fs;

use Demo\Pmd\Hilos;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;

/** Shared uploads, published files and exports, with a node-local analytics journal. */
final class PmdFsContext extends FsContext
{
    /** Registers the storage directories used by PMD's activated features. */
    public function configure(): void
    {
        $this->setTmpPath(dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/files/tmp', DirectoryScope::CLUSTER);
        $this->registerDirectory(
            FsContext::FILES,
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/files/published',
            DirectoryScope::CLUSTER,
        );
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

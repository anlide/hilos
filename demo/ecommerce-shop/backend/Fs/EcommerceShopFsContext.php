<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Fs;

use Demo\EcommerceShop\Hilos;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;

/** Shared account exports, public Status files and catalog pictures, and the node-local analytics journal. */
final class EcommerceShopFsContext extends FsContext
{
    /** Registers shared account export, Status, upload and published-file storage beside the node's analytics journal. */
    public function configure(): void
    {
        $this->setTmpPath(dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/files/tmp', DirectoryScope::CLUSTER);
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
        $this->registerDirectory(
            FsContext::FILES,
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/files/published',
            DirectoryScope::CLUSTER,
        );
    }
}

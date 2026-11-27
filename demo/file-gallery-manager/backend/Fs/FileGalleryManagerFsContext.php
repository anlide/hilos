<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Fs;

use Demo\FileGalleryManager\Hilos;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;

/** Shared account exports and the node-local analytics journal. */
final class FileGalleryManagerFsContext extends FsContext
{
    /**
     * Registers shared account exports and node-local analytics journal storage.
     *
     * @throws EnvException When the IP-to-country directory override cannot be read
     */
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
        $this->registerIpCountryDirectory(dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/ip_country');
    }
}

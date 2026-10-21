<?php

declare(strict_types=1);

namespace Demo\Polls\Fs;

use Demo\Polls\Constants\PollsEnvConstants;
use Demo\Polls\Hilos;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;

/** Shared file and export directories and the node-local analytics journal. */
final class PollsFsContext extends FsContext
{
    /**
     * Registers shared files and exports and node-local analytics journal storage.
     *
     * @throws EnvException When the published-files directory override cannot be read
     */
    public function configure(): void
    {
        $filesPath = Hilos::$env[PollsEnvConstants::POLLS_FILES_PUBLISHED_DIR]->string();
        $this->setTmpPath(
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/files/tmp',
            DirectoryScope::CLUSTER,
        );
        $this->registerDirectory(
            FsContext::FILES,
            $filesPath !== '' ? $filesPath : dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/files/published',
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
        $this->registerDirectory(
            FsContext::LEGAL_EXPORT,
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/legal_export',
            DirectoryScope::CLUSTER,
        );
    }
}

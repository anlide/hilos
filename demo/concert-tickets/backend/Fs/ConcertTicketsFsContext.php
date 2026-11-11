<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Fs;

use Demo\ConcertTickets\Constants\ConcertTicketsEnvConstants;
use Demo\ConcertTickets\Hilos;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;

/** Shared file and export directories and the node-local analytics journal. */
final class ConcertTicketsFsContext extends FsContext
{
    /**
     * Registers shared files and exports and node-local analytics journal storage.
     *
     * @throws EnvException When the published-files directory override cannot be read
     */
    public function configure(): void
    {
        $filesPath = Hilos::$env[ConcertTicketsEnvConstants::CONCERT_TICKETS_FILES_PUBLISHED_DIR]->string();
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
    }
}

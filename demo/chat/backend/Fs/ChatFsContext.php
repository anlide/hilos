<?php

declare(strict_types=1);

namespace Demo\Chat\Fs;

use Demo\Chat\Constants\ChatEnvConstants;
use Demo\Chat\Hilos;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsDirectory;
use Hilos\Fs\FsTmpDirectory;

/**
 * Chat-project filesystem context: tmp, the files registry's directory, data exports and the
 * analytics journal.
 *
 * Tmp is the node's - only the connection's process touches it, and the uploads agent keeps the
 * chunks of a file there. The files directory and the exports are the cluster's. The analytics
 * journal is the node's as well: each node's journal agent keeps its own files there (HIL-1154),
 * under a subdirectory of the environment and the node, so the environments that mount this one
 * data directory never see each other's.
 *
 * @property-read FsTmpDirectory $tmp
 * @property-read FsDirectory $files Where the files registry keeps the chat's attachments
 */
final class ChatFsContext extends FsContext
{
    private const string STORAGE_DIR = 'chat_attachments';

    /** Subdirectory the chat published its attachments into before the registry, kept so no file moves. */
    private const string FILES_DIR = 'published';

    /**
     * Project-relative base: demo/chat/data/chat_attachments.
     */
    private static function defaultBaseDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . Hilos::DATA_DIR . DIRECTORY_SEPARATOR . self::STORAGE_DIR;
    }

    public function configure(): void
    {
        $base = self::defaultBaseDir();
        $filesPath = Hilos::$env[ChatEnvConstants::CHAT_FILES_PUBLISHED_DIR]->string();

        $this->setTmpPath($base . DIRECTORY_SEPARATOR . self::TMP, DirectoryScope::NODE);

        // The files registry keeps its files where attachments were published before it (HIL-336):
        // the earlier attachments became registry rows without a file moving (HIL-144), and the
        // web server serves from the same directory. CHAT_FILES_PUBLISHED_DIR moves it.
        $this->registerDirectory(
            FsContext::FILES,
            $filesPath !== '' ? $filesPath : $base . DIRECTORY_SEPARATOR . self::FILES_DIR,
            DirectoryScope::CLUSTER,
        );
        $this->registerDirectory(
            FsContext::DATA_EXPORT,
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/data_export',
            DirectoryScope::CLUSTER,
        );
        $this->registerDirectory(
            FsContext::ANALYTICS_JOURNAL,
            dirname(__DIR__, 2) . '/' . Hilos::DATA_DIR . '/analytics_journal',
            DirectoryScope::NODE,
        );
    }
}

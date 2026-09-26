<?php

declare(strict_types=1);

namespace Demo\Chat\Fs;

use Demo\Chat\Constants\ChatEnvConstants;
use Demo\Chat\Hilos;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\FsDirectory;
use Hilos\Fs\FsTmpDirectory;

/**
 * Chat-project filesystem context: quarantine, published, files, and tmp directories.
 *
 * @property-read FsTmpDirectory $tmp
 * @property-read FsDirectory $quarantine
 * @property-read FsDirectory $published
 * @property-read FsDirectory $files The published directory under the name the files registry reads
 */
final class ChatFsContext extends FsContext
{
    public const string quarantine = 'quarantine';

    public const string published = 'published';

    private const string STORAGE_DIR = 'chat_attachments';

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
        $quarantinePath = Hilos::$env[ChatEnvConstants::CHAT_FILES_QUARANTINE_DIR]->string();
        $publishedPath = Hilos::$env[ChatEnvConstants::CHAT_FILES_PUBLISHED_DIR]->string();

        $this->setTmpPath($base . DIRECTORY_SEPARATOR . self::TMP);

        $this->registerDirectory(
            self::quarantine,
            $quarantinePath !== '' ? $quarantinePath : $base . DIRECTORY_SEPARATOR . self::quarantine,
        );

        // The files registry keeps its files where attachments are published today (HIL-336):
        // moving attachments onto the registry (HIL-144) then moves no file, and the web server
        // already serves from there. CHAT_FILES_PUBLISHED_DIR moves both names at once.
        $publishedDirectory = $publishedPath !== '' ? $publishedPath : $base . DIRECTORY_SEPARATOR . self::published;
        $this->registerDirectory(self::published, $publishedDirectory);
        $this->registerDirectory(FsContext::FILES, $publishedDirectory);
    }
}

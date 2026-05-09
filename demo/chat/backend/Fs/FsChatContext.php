<?php

declare(strict_types=1);

namespace Demo\Chat\Fs;

use Demo\Chat\Constants\ChatEnvConstants;
use Demo\Chat\Hilos;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\FsDirectory;
use Hilos\Fs\FsTmpDirectory;

/**
 * Chat-project filesystem context: quarantine, published, and tmp directories.
 *
 * @property-read FsTmpDirectory $tmp
 * @property-read FsDirectory $quarantine
 * @property-read FsDirectory $published
 */
final class FsChatContext extends FsContext
{
    public const string quarantine = 'quarantine';

    public const string published = 'published';

    /**
     * Project-relative base: demo/chat/data/chat_attachments.
     */
    private static function defaultBaseDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'chat_attachments';
    }

    public function configure(): void
    {
        $base = self::defaultBaseDir();
        $quarantinePath = Hilos::$env[ChatEnvConstants::CHAT_FILES_QUARANTINE_DIR];
        $publishedPath = Hilos::$env[ChatEnvConstants::CHAT_FILES_PUBLISHED_DIR];

        $this->setTmpPath($base . DIRECTORY_SEPARATOR . 'tmp');

        $this->registerDirectory(
            self::quarantine,
            $quarantinePath !== '' ? $quarantinePath : $base . DIRECTORY_SEPARATOR . 'quarantine',
        );

        $this->registerDirectory(
            self::published,
            $publishedPath !== '' ? $publishedPath : $base . DIRECTORY_SEPARATOR . 'published',
        );
    }
}

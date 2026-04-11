<?php

declare(strict_types=1);

namespace Demo\Chat\Fs;

use Hilos\Fs\Context\FsContext;
use Hilos\Fs\FsDirectory;
use Hilos\Fs\FsTmpDirectory;
use Hilos\Utils\Env;

/**
 * Chat-project filesystem context: quarantine, published, and tmp directories.
 *
 * @property-read FsTmpDirectory $tmp
 * @property-read FsDirectory $quarantine
 * @property-read FsDirectory $published
 */
final class FsChatContext extends FsContext
{
    private const string ENV_QUARANTINE = 'CHAT_FILES_QUARANTINE_DIR';

    private const string ENV_PUBLISHED = 'CHAT_FILES_PUBLISHED_DIR';

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

        $this->setTmpPath($base . DIRECTORY_SEPARATOR . 'tmp');

        $this->registerDirectory(
            self::quarantine,
            Env::get(self::ENV_QUARANTINE, $base . DIRECTORY_SEPARATOR . 'quarantine'),
        );

        $this->registerDirectory(
            self::published,
            Env::get(self::ENV_PUBLISHED, $base . DIRECTORY_SEPARATOR . 'published'),
        );
    }
}

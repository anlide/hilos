<?php

declare(strict_types=1);

namespace Demo\Chat\Utils;

use Hilos\Utils\Env;

/**
 * ChatAttachmentStorage - Quarantine and published paths for chat attachments.
 *
 * Default layout is fixed under the demo/chat project tree ({@see self::defaultAttachmentsBaseDir()}),
 * e.g. Docker bind-mount ..:/app → /app/data/chat_attachments. This is independent of DAEMON_LOG_FILE
 * (logs may be bind-mounted elsewhere, e.g. ../data/logs:/var/log/hilos).
 *
 * Optional override: CHAT_FILES_QUARANTINE_DIR and CHAT_FILES_PUBLISHED_DIR.
 */
final class ChatAttachmentStorage
{
    private const string ENV_QUARANTINE = 'CHAT_FILES_QUARANTINE_DIR';

    private const string ENV_PUBLISHED = 'CHAT_FILES_PUBLISHED_DIR';

    /**
     * Project-relative base: demo/chat/data/chat_attachments (backend/Utils → two levels up = demo/chat root).
     */
    private static function defaultAttachmentsBaseDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'chat_attachments';
    }

    /**
     * Absolute path to quarantine directory (incomplete uploads).
     */
    public static function quarantineDir(): string
    {
        $default = self::defaultAttachmentsBaseDir() . DIRECTORY_SEPARATOR . 'quarantine';
        $dir = Env::get(self::ENV_QUARANTINE, $default);
        self::ensureDir($dir);

        return $dir;
    }

    /**
     * Absolute path to published directory (approved files, HTTP-servable).
     */
    public static function publishedDir(): string
    {
        $default = self::defaultAttachmentsBaseDir() . DIRECTORY_SEPARATOR . 'published';
        $dir = Env::get(self::ENV_PUBLISHED, $default);
        self::ensureDir($dir);

        return $dir;
    }

    /**
     * Full path for a quarantine temp file (basename only, no path traversal).
     */
    public static function quarantinePathForBasename(string $basename): string
    {
        $safe = basename($basename);

        return self::quarantineDir() . DIRECTORY_SEPARATOR . $safe;
    }

    /**
     * Full path for a published stored file.
     */
    public static function publishedPathForBasename(string $basename): string
    {
        $safe = basename($basename);

        return self::publishedDir() . DIRECTORY_SEPARATOR . $safe;
    }

    /**
     * Guess file extension from MIME type (fallback .bin).
     */
    public static function extensionForMime(string $mimeType): string
    {
        $mime = strtolower(trim($mimeType));
        $map = [
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            'application/pdf' => '.pdf',
            'text/plain' => '.txt',
        ];

        return $map[$mime] ?? '.bin';
    }

    public static function deleteIfExists(string $absolutePath): void
    {
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    public static function moveToPublished(string $quarantineAbsolute, string $publishedBasename): bool
    {
        $dest = self::publishedPathForBasename($publishedBasename);
        self::ensureDir(dirname($dest));

        return @rename($quarantineAbsolute, $dest);
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}

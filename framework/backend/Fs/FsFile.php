<?php

declare(strict_types=1);

namespace Hilos\Fs;

use finfo;
use Hilos\Constants\HttpConstants;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;

/**
 * Handle for a single file inside a named FsDirectory.
 *
 * Lightweight value object — no existence check on construction.
 */
final readonly class FsFile
{
    private string $path;

    /**
     * @param FsDirectory $directory Owning named directory
     * @param string $filename Basename inside the directory
     */
    public function __construct(
        private FsDirectory $directory,
        private string $filename,
    ) {
        $this->path = $this->directory->getPath() . DIRECTORY_SEPARATOR . basename($this->filename);
    }

    /**
     * @param string $data Binary payload to append
     *
     * @throws FileWriteException If append fails
     */
    public function append(string $data): void
    {
        FsPath::append($this->path, $data);
    }

    /**
     * Delete the file (no-op when already absent).
     *
     * @throws FileDeleteException If the file exists but cannot be removed
     */
    public function unlink(): void
    {
        FsPath::delete($this->path);
    }

    /**
     * @return string Detected MIME type
     *
     * @throws FileNotFoundException If the file does not exist
     */
    public function getMimeType(): string
    {
        if (!is_file($this->path)) {
            throw new FileNotFoundException("File not found: {$this->filename}");
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        return $finfo->file($this->path) ?: HttpConstants::CONTENT_TYPE_OCTET_STREAM;
    }

    /**
     * @return int File size in bytes
     *
     * @throws FileNotFoundException If the file does not exist
     */
    public function size(): int
    {
        if (!is_file($this->path)) {
            throw new FileNotFoundException("File not found: {$this->filename}");
        }

        return filesize($this->path) ?: 0;
    }

    /**
     * @param string $targetDirectoryName Registered directory name (e.g. 'published')
     *
     * @throws DirectoryCreateException If the target directory cannot be created
     * @throws DirectoryNotFoundException If the target directory is not registered
     * @throws FileMoveException If the rename operation fails
     * @throws FileNotFoundException If the source file does not exist
     */
    public function move(string $targetDirectoryName): void
    {
        if (!is_file($this->path)) {
            throw new FileNotFoundException("File not found: {$this->filename}");
        }
        $targetDir = $this->directory->getContext()->getDirectory($targetDirectoryName);
        $targetDir->ensureDirectory();
        FsPath::move($this->path, $targetDir->getPath() . DIRECTORY_SEPARATOR . basename($this->filename));
    }

    /**
     * @return string File contents
     *
     * @throws FileNotFoundException If the file does not exist
     * @throws FileReadException If the read operation fails
     */
    public function read(): string
    {
        if (!is_file($this->path)) {
            throw new FileNotFoundException("File not found: {$this->filename}");
        }
        $content = file_get_contents($this->path);
        if ($content === false) {
            throw new FileReadException("Cannot read file: {$this->filename}");
        }

        return $content;
    }

    /**
     * @return bool Whether the file exists on disk
     */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return string Absolute filesystem path
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return string Basename in the owning directory
     */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * Map MIME type to a conventional file extension (with leading dot).
     *
     * @param string $mimeType MIME type to map
     * @return string Extension with leading dot
     */
    public static function extensionForMime(string $mimeType): string
    {
        return match (strtolower(trim($mimeType))) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            'application/pdf' => '.pdf',
            'text/plain' => '.txt',
            default => '.bin',
        };
    }
}

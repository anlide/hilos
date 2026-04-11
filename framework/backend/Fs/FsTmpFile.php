<?php

declare(strict_types=1);

namespace Hilos\Fs;

use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileWriteException;

/**
 * Handle for a single file inside {@see FsTmpDirectory}.
 *
 * Lightweight value object — no existence check on construction.
 */
final class FsTmpFile
{
    private readonly string $path;

    /**
     * @param FsTmpDirectory $directory Owning tmp directory
     * @param string $index Filename (hex index) inside the tmp directory
     */
    public function __construct(
        private readonly FsTmpDirectory $directory,
        private readonly string $index,
    ) {
        $this->path = $this->directory->getPath() . DIRECTORY_SEPARATOR . basename($this->index);
    }

    /**
     * Append binary payload to the file.
     *
     * @throws FileWriteException If append fails
     */
    public function append(string $data): void
    {
        if (file_put_contents($this->path, $data, FILE_APPEND) === false) {
            throw new FileWriteException("Cannot append to tmp file: {$this->index}");
        }
    }

    /**
     * Delete the file (no-op when already absent).
     *
     * @throws FileDeleteException If the file exists but cannot be removed
     */
    public function unlink(): void
    {
        if (is_file($this->path) && !@unlink($this->path)) {
            throw new FileDeleteException("Cannot delete tmp file: {$this->index}");
        }
    }

    /**
     * @return int File size in bytes (0 when absent)
     */
    public function size(): int
    {
        return is_file($this->path) ? (filesize($this->path) ?: 0) : 0;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getIndex(): string
    {
        return $this->index;
    }
}

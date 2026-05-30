<?php

declare(strict_types=1);

namespace Hilos\Fs;

use ArrayAccess;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Utils\Helpers\RandomHelper;
use LogicException;

/**
 * Temporary-file directory with auto-generated hex-based filenames.
 *
 * @implements ArrayAccess<string, FsTmpFile>
 */
final readonly class FsTmpDirectory implements ArrayAccess
{
    /**
     * @param string $path Absolute filesystem path
     */
    public function __construct(
        private string $path,
    ) {
    }

    /**
     * Create an empty tmp file with a random hex name.
     *
     * @return string Generated hex index for ArrayAccess lookup
     *
     * @throws DirectoryCreateException If the tmp directory cannot be created
     * @throws FileWriteException If the file cannot be created
     */
    public function create(): string
    {
        $this->ensureDirectory();
        $index = RandomHelper::hex(16);
        $path = $this->path . DIRECTORY_SEPARATOR . $index;
        if (file_put_contents($path, '') === false) {
            throw new FileWriteException("Cannot create tmp file: {$index}");
        }

        return $index;
    }

    /**
     * @throws DirectoryCreateException If the directory cannot be created
     */
    public function ensureDirectory(): void
    {
        if (!is_dir($this->path) && !@mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            throw new DirectoryCreateException("Cannot create tmp directory: {$this->path}");
        }
    }

    /**
     * @return string Absolute filesystem path
     */
    public function getPath(): string
    {
        return $this->path;
    }

    // ── ArrayAccess ──────────────────────────────────────────────

    /**
     * @param string $offset Hex index from create()
     * @return bool Whether the tmp file exists on disk
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_file($this->path . DIRECTORY_SEPARATOR . basename((string)$offset));
    }

    /**
     * @param string $offset Hex index from create()
     * @return FsTmpFile File handle without existence check
     */
    public function offsetGet(mixed $offset): FsTmpFile
    {
        return new FsTmpFile($this, (string)$offset);
    }

    /**
     * @param mixed $offset ArrayAccess offset
     * @param mixed $value Ignored write value
     *
     * @throws LogicException Always; use create() instead
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('FsTmpDirectory is read-only via ArrayAccess; use create()');
    }

    /**
     * @param mixed $offset ArrayAccess offset
     *
     * @throws LogicException Always; use FsTmpFile::unlink() instead
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('FsTmpDirectory is read-only via ArrayAccess; use $file->unlink()');
    }
}

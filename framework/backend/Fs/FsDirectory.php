<?php

declare(strict_types=1);

namespace Hilos\Fs;

use ArrayAccess;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileWriteException;
use LogicException;

/**
 * Named file directory registered in FsContext (e.g. quarantine, published).
 *
 * @implements ArrayAccess<string, FsFile>
 */
final readonly class FsDirectory implements ArrayAccess
{
    /**
     * @param FsContext $context Owning FS context for cross-directory file operations
     * @param string $name Logical directory name as registered in the context
     * @param string $path Absolute filesystem path
     */
    public function __construct(
        private FsContext $context,
        private string $name,
        private string $path,
    ) {
    }

    /**
     * @param string $filename Target basename inside this directory
     * @return FsFile Created file handle
     *
     * @throws DirectoryCreateException If the directory cannot be created
     * @throws FileWriteException If the file cannot be created
     */
    public function create(string $filename): FsFile
    {
        $this->ensureDirectory();
        $safe = basename($filename);
        $full = $this->path . DIRECTORY_SEPARATOR . $safe;
        if (file_put_contents($full, '') === false) {
            throw new FileWriteException("Cannot create file: {$safe} in {$this->name}");
        }

        return new FsFile($this, $safe);
    }

    /**
     * @param string $filename Target basename inside this directory
     * @param string $tmpIndex Hex index of the source tmp file
     * @return FsFile Created file handle
     *
     * @throws DirectoryCreateException If the directory cannot be created
     * @throws DirectoryNotFoundException If the tmp directory is not configured
     * @throws FileMoveException If the rename operation fails
     * @throws FileNotFoundException If the tmp source file does not exist
     */
    public function createFromTmp(string $filename, string $tmpIndex): FsFile
    {
        $this->ensureDirectory();
        $tmpFile = $this->context->getTmp()[$tmpIndex];
        $source = $tmpFile->getPath();
        if (!is_file($source)) {
            throw new FileNotFoundException("Tmp file not found: {$tmpIndex}");
        }
        $safe = basename($filename);
        FsPath::move($source, $this->path . DIRECTORY_SEPARATOR . $safe);

        return new FsFile($this, $safe);
    }

    /**
     * Best-effort delete of every regular file (non-recursive).
     */
    public function deleteAll(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        foreach (scandir($this->path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $this->path . DIRECTORY_SEPARATOR . $entry;
            if (is_file($full)) {
                try {
                    FsPath::delete($full);
                } catch (FileDeleteException) {
                    // best-effort by contract: one undeletable file does not stop the sweep
                }
            }
        }
    }

    /**
     * Total size of all files in the directory (non-recursive).
     *
     * @return int Total size in bytes
     */
    public function size(): int
    {
        if (!is_dir($this->path)) {
            return 0;
        }
        $total = 0;
        foreach (scandir($this->path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $this->path . DIRECTORY_SEPARATOR . $entry;
            if (is_file($full)) {
                $total += filesize($full) ?: 0;
            }
        }

        return $total;
    }

    /**
     * @throws DirectoryCreateException If the directory cannot be created
     */
    public function ensureDirectory(): void
    {
        FsPath::ensureDirectory($this->path);
    }

    /**
     * @return FsContext Owning FS context
     */
    public function getContext(): FsContext
    {
        return $this->context;
    }

    /**
     * @return string Logical directory name
     */
    public function getName(): string
    {
        return $this->name;
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
     * @param string $offset Filename inside this directory
     * @return bool Whether the filename exists as a regular file
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_file($this->path . DIRECTORY_SEPARATOR . basename((string)$offset));
    }

    /**
     * @param string $offset Filename inside this directory
     * @return FsFile File handle without existence check
     */
    public function offsetGet(mixed $offset): FsFile
    {
        return new FsFile($this, (string)$offset);
    }

    /**
     * @param mixed $offset ArrayAccess offset
     * @param mixed $value Ignored write value
     *
     * @throws LogicException Always; use create() or createFromTmp() instead
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('FsDirectory is read-only via ArrayAccess; use create() or createFromTmp()');
    }

    /**
     * @param mixed $offset ArrayAccess offset
     *
     * @throws LogicException Always; use FsFile::unlink() instead
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('FsDirectory is read-only via ArrayAccess; use $file->unlink()');
    }
}

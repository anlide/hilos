<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use BadMethodCallException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use JsonException;
use Phar;
use PharData;
use PharException;
use RuntimeException;

/** An uncompressed ZIP containing JSON sections and original attachment bytes (HIL-303). */
final class DataExportArchive implements DataExportWriter
{
    private const string FILES_PREFIX = 'files/';
    private const string JSON_EXTENSION = '.json';

    private PharData $archive;
    private bool $closed = false;

    /**
     * @param string $path New archive path, ending in .zip
     * @throws FsException When the path exists or Phar cannot create the archive
     */
    public function __construct(private readonly string $path)
    {
        if (file_exists($path) || is_link($path)) {
            throw new FsException('A data export must start with a new archive');
        }

        try {
            $this->archive = new PharData($path, 0, null, Phar::ZIP);
            $this->archive->startBuffering();
        } catch (RuntimeException | BadMethodCallException | PharException $e) {
            throw new FsException('Cannot create the data export archive', previous: $e);
        }
    }

    /**
     * @param string $name Section basename, without an extension
     * @param ?array<array-key, mixed> $data JSON section; null represents an absent optional section
     * @throws FsException When the section name, JSON encoding, or archive write is invalid
     */
    public function section(string $name, ?array $data): void
    {
        self::requireBasename($name);
        $this->json($name . self::JSON_EXTENSION, $data);
    }

    /**
     * @param string $archivePath Relative JSON entry path
     * @param ?array<array-key, mixed> $data Data at the JSON serialization boundary
     * @throws FsException When the path, JSON encoding, or archive write is invalid
     */
    public function json(string $archivePath, ?array $data): void
    {
        try {
            $this->text($archivePath, json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } catch (JsonException $e) {
            throw new FsException('Cannot encode a data export section', previous: $e);
        }
    }

    /**
     * @param string $archivePath Relative entry path
     * @param string $contents Entry bytes, including the root README.txt
     * @throws FsException When the path or archive write is invalid
     */
    public function text(string $archivePath, string $contents): void
    {
        $this->requireNewEntry($archivePath);
        try {
            $this->archive->addFromString($archivePath, $contents);
        } catch (RuntimeException | BadMethodCallException | PharException $e) {
            throw new FsException('Cannot write a data export entry', previous: $e);
        }
    }

    /**
     * @param string $name Attachment basename inside files/
     * @param string $sourcePath Source file on disk
     * @return string Relative archive path to reference from a JSON section
     * @throws FsException When the name is invalid or the file cannot be archived
     */
    public function file(string $name, string $sourcePath): string
    {
        self::requireBasename($name);
        $archivePath = self::FILES_PREFIX . $name;
        $this->requireNewEntry($archivePath);
        try {
            $this->archive->addFile($sourcePath, $archivePath);
        } catch (RuntimeException | BadMethodCallException | PharException $e) {
            throw new FsException('Cannot write a data export attachment', previous: $e);
        }

        return $archivePath;
    }

    /**
     * @return int Final archive size in bytes
     * @throws FsException When the archive cannot be finalized or measured
     */
    public function close(): int
    {
        try {
            $this->archive->stopBuffering();
            $this->closed = true;
        } catch (RuntimeException | BadMethodCallException | PharException $e) {
            throw new FsException('Cannot finish the data export archive', previous: $e);
        }

        return FsPath::size($this->path);
    }

    /**
     * @param string $name One archive path component
     * @throws FsException When the name is empty, traverses directories, or is unsafe on extraction
     */
    private static function requireBasename(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..' || preg_match('/[\x00-\x1f\x7f\\\\\/:]/', $name) === 1) {
            throw new FsException('Invalid data export entry name');
        }
    }

    /**
     * @param string $archivePath Relative entry path
     * @throws FsException When the archive is closed, the path is invalid, or the entry already exists
     */
    private function requireNewEntry(string $archivePath): void
    {
        if ($this->closed) {
            throw new FsException('The data export archive is closed');
        }
        foreach (explode('/', $archivePath) as $part) {
            self::requireBasename($part);
        }
        if (isset($this->archive[$archivePath])) {
            throw new FsException('Duplicate data export entry: ' . $archivePath);
        }
    }
}

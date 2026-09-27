<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use Hilos\Fs\FsException;

/** The serialization boundary shared by framework and project account exports. */
interface DataExportWriter
{
    /**
     * @param string $name Section basename, without an extension
     * @param ?array<array-key, mixed> $data JSON section; null represents an absent optional section
     * @throws FsException When the section name, JSON encoding, or archive write is invalid
     */
    public function section(string $name, ?array $data): void;

    /**
     * @param string $name Attachment basename inside files/
     * @param string $sourcePath Source file on disk
     * @return string Relative archive path to reference from a JSON section
     * @throws FsException When the name is invalid or the file cannot be archived
     */
    public function file(string $name, string $sourcePath): string;
}

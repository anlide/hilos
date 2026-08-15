<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupProgressRead - what {@see BackupProgressMarker::read()} found in one chunk of child stdout.
 *
 * Immutable value object. A chunk is whatever the pipe happened to hand over on one tick, so it
 * both ends mid-line and carries several lines at once: the recognized phase values come out in
 * arrival order, and the trailing partial line comes back as the tail its reader must prepend to
 * the next chunk. Everything that is not a marker line - the child's own output, an unknown phase
 * token - is dropped here rather than travelling on.
 */
final class BackupProgressRead
{
    /**
     * @param list<string> $phases Recognized phase values, in the order the child announced them
     * @param string $tail Trailing fragment with no line break yet; belongs in front of the next chunk
     */
    public function __construct(
        public readonly array $phases,
        public readonly string $tail,
    ) {
    }
}

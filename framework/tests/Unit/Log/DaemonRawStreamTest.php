<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Log\DaemonRawStream;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the one function naming the daemon's raw output streams (HIL-480).
 *
 * Three callers derive that name — the watchdog pointing descriptors at it, the store reader
 * classifying it, the rotator keeping it back — so the shapes an env path can take are pinned
 * here: an ordinary `.log` name, a name with no extension at all, and dots in the directory
 * above the file.
 */
final class DaemonRawStreamTest extends TestCase
{
    public function testSuffixGoesBeforeTheExtension(): void
    {
        $this->assertSame('/var/log/hilos/daemon-raw.log', DaemonRawStream::pathFor('/var/log/hilos/daemon.log'));
        $this->assertSame(
            '/var/log/hilos/daemon-error-raw.log',
            DaemonRawStream::pathFor('/var/log/hilos/daemon-error.log'),
        );
    }

    public function testExtensionlessPathGetsTheSuffixAndTheLogExtension(): void
    {
        $this->assertSame('/var/log/hilos/daemon-raw.log', DaemonRawStream::pathFor('/var/log/hilos/daemon'));
    }

    public function testDotsInTheDirectoryDoNotCountAsTheExtension(): void
    {
        $this->assertSame('/srv/app.v2/logs/daemon-raw.log', DaemonRawStream::pathFor('/srv/app.v2/logs/daemon.log'));
        // The dot of `app.v2` is the last one in the whole path, and it is not the extension.
        $this->assertSame('/srv/app.v2/logs/daemon-raw.log', DaemonRawStream::pathFor('/srv/app.v2/logs/daemon'));
    }
}

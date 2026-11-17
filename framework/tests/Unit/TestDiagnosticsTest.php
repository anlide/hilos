<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use ErrorException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/test-diagnostics.php';

final class TestDiagnosticsTest extends TestCase
{
    /** Even a warning-converting handler cannot turn a refused observation into a test failure. */
    public function testUnwritableSinkCannotReplaceTheCommandResult(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            // A directory cannot be opened as an append file; no fixture or subprocess is needed.
            $at = testPhase('probe', 'unit', 'begin', path: sys_get_temp_dir());
        } finally {
            restore_error_handler();
        }

        self::assertGreaterThan(0, $at);
    }
}

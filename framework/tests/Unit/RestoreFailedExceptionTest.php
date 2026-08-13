<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Exception\RestoreFailedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the one thing a failed restore has to say besides its message (HIL-436).
 *
 * "The database was not touched" and "the database may be half-replaced" are two different
 * emergencies, and the exit code the restore child returns is derived from exactly this flag. The
 * default matters as much as the factories: every refusal outside the destructive window is a plain
 * construction, and if that read as "touched" every archive typo would send an operator looking for
 * damage that never happened.
 */
final class RestoreFailedExceptionTest extends TestCase
{
    public function testAPlainFailureLeftTheDatabaseAlone(): void
    {
        $failure = new RestoreFailedException('Invalid backup id: nonsense');

        $this->assertFalse($failure->databaseTouched());
    }

    public function testAFailureBeforeTheFirstDestructiveStepLeftTheDatabaseAlone(): void
    {
        $failure = RestoreFailedException::beforeDestructive('Archive digest does not match');

        $this->assertFalse($failure->databaseTouched());
        $this->assertSame('Archive digest does not match', $failure->getMessage());
    }

    public function testAFailureInsideTheDestructiveWindowSaysTheDatabaseMayBeReplaced(): void
    {
        $failure = RestoreFailedException::afterDestructive('Import failed for connection 1');

        $this->assertTrue($failure->databaseTouched());
        $this->assertSame('Import failed for connection 1', $failure->getMessage());
    }

    public function testTheUnderlyingFailureIsKeptSoTheOperatorSeesTheRealCause(): void
    {
        $cause = new RuntimeException('mysql: connection lost');

        $before = RestoreFailedException::beforeDestructive('Unpacking failed', $cause);
        $after = RestoreFailedException::afterDestructive('Import failed', $cause);

        $this->assertSame($cause, $before->getPrevious());
        $this->assertSame($cause, $after->getPrevious());
    }
}

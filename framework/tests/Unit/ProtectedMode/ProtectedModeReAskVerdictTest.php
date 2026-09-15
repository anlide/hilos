<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\ProtectedMode\ProtectedModeReAskVerdict;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Pins how a caller reads the protected-mode snapshot after its drive reply was lost.
 */
final class ProtectedModeReAskVerdictTest extends TestCase
{
    private const string OPERATION = 'unit-restore';

    public function testAStandingFreezeRecoversTheLostDriveAsTaken(): void
    {
        self::assertSame(
            ProtectedModeReAskVerdict::TAKEN,
            ProtectedModeReAskVerdict::read(
                $this->snapshot(ProtectedModeRuntime::PHASE_ACTIVE),
                self::OPERATION,
                [ProtectedModeRuntime::PHASE_ACTIVE],
            ),
        );
    }

    public function testAProjectWithoutProtectedModeReadsNotTaken(): void
    {
        $snapshot = $this->snapshot(ProtectedModeRuntime::PHASE_ACTIVE);
        $snapshot[ProtectedModeCommandConstants::FIELD_RT_MOUNTED] = false;

        self::assertSame(
            ProtectedModeReAskVerdict::NOT_TAKEN,
            ProtectedModeReAskVerdict::read(
                $snapshot,
                self::OPERATION,
                [ProtectedModeRuntime::PHASE_ACTIVE],
            ),
        );
    }

    public function testAFreezeForAnotherOperationReadsNotTaken(): void
    {
        $snapshot = $this->snapshot(ProtectedModeRuntime::PHASE_ACTIVE);
        $snapshot[ProtectedModeCommandConstants::FIELD_OPERATION] = 'another-operation';

        self::assertSame(
            ProtectedModeReAskVerdict::NOT_TAKEN,
            ProtectedModeReAskVerdict::read(
                $snapshot,
                self::OPERATION,
                [ProtectedModeRuntime::PHASE_ACTIVE],
            ),
        );
    }

    public function testAnActivatingFreezeReadsUnderWay(): void
    {
        self::assertSame(
            ProtectedModeReAskVerdict::UNDER_WAY,
            ProtectedModeReAskVerdict::read(
                $this->snapshot(ProtectedModeRuntime::PHASE_ACTIVATING),
                self::OPERATION,
                [ProtectedModeRuntime::PHASE_ACTIVE],
            ),
        );
    }

    public function testAPhaseOutsideTheCallersTakenSetReadsNotTaken(): void
    {
        self::assertSame(
            ProtectedModeReAskVerdict::NOT_TAKEN,
            ProtectedModeReAskVerdict::read(
                $this->snapshot(ProtectedModeRuntime::PHASE_VERIFYING),
                self::OPERATION,
                [ProtectedModeRuntime::PHASE_ACTIVE],
            ),
        );
    }

    /**
     * @param string $phase Protected-mode phase to place on the snapshot
     * @return array<string, mixed> Snapshot for one mounted protected-mode row
     */
    private function snapshot(string $phase): array
    {
        return [
            ProtectedModeCommandConstants::FIELD_RT_MOUNTED => true,
            ProtectedModeCommandConstants::FIELD_OPERATION => self::OPERATION,
            ProtectedModeCommandConstants::FIELD_PHASE => $phase,
        ];
    }
}

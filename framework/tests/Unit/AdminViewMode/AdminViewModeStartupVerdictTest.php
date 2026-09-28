<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\AdminViewMode;

use Hilos\AdminViewMode\AdminViewModeStartupDecision;
use Hilos\AdminViewMode\AdminViewModeStartupVerdict;
use PHPUnit\Framework\TestCase;

/**
 * The admin view mode decided at the start of a node, one row of the table per case (HIL-1249).
 *
 * The mode works in production: a production node started with the variable on and no latch has
 * it on. What production adds is the latch, and a latch - either half of it - keeps the mode off
 * whatever the variable says. A stand has the mode the variable says and owes the latch nothing.
 */
final class AdminViewModeStartupVerdictTest extends TestCase
{
    public function testAStandWithTheVariableOnHasTheModeOn(): void
    {
        $this->assertEquals(new AdminViewModeStartupDecision(true), AdminViewModeStartupVerdict::decide(true, true, false, false));
    }

    public function testAStandWithTheVariableOffHasTheModeOff(): void
    {
        $this->assertEquals(new AdminViewModeStartupDecision(false), AdminViewModeStartupVerdict::decide(true, false, false, false));
    }

    /**
     * Whatever the latch would say, a stand neither reads it nor writes it: the inputs a stand
     * could be handed change nothing, and no half is owed.
     */
    public function testAStandNeitherReadsNorWritesTheLatch(): void
    {
        foreach ([[true, true], [true, false], [false, true]] as [$file, $row]) {
            $this->assertEquals(new AdminViewModeStartupDecision(true), AdminViewModeStartupVerdict::decide(true, true, $file, $row));
            $this->assertEquals(new AdminViewModeStartupDecision(false), AdminViewModeStartupVerdict::decide(true, false, $file, $row));
        }
    }

    public function testProductionWithTheVariableOnAndNoLatchTurnsTheModeOn(): void
    {
        $this->assertEquals(
            new AdminViewModeStartupDecision(true, onInProduction: true),
            AdminViewModeStartupVerdict::decide(false, true, false, false),
        );
    }

    public function testProductionWithTheVariableOffAndNoLatchClosesTheModeForGood(): void
    {
        $this->assertEquals(
            new AdminViewModeStartupDecision(false, writeFile: true, writeRow: true, closedForGood: true),
            AdminViewModeStartupVerdict::decide(false, false, false, false),
        );
    }

    public function testALatchKeepsTheModeOffWhateverTheVariableSays(): void
    {
        foreach ([[true, true], [true, false], [false, true]] as [$file, $row]) {
            $this->assertFalse(AdminViewModeStartupVerdict::decide(false, true, $file, $row)->enabled);
            $this->assertFalse(AdminViewModeStartupVerdict::decide(false, false, $file, $row)->enabled);
        }
    }

    public function testAMissingHalfIsWrittenBackFromTheOther(): void
    {
        $this->assertEquals(
            new AdminViewModeStartupDecision(false, writeRow: true),
            AdminViewModeStartupVerdict::decide(false, false, true, false),
        );
        $this->assertEquals(
            new AdminViewModeStartupDecision(false, writeFile: true),
            AdminViewModeStartupVerdict::decide(false, false, false, true),
        );
    }

    public function testAMissingHalfIsWrittenBackAndTheConflictReportedWhenTheVariableIsOn(): void
    {
        $this->assertEquals(
            new AdminViewModeStartupDecision(false, writeRow: true, conflict: true),
            AdminViewModeStartupVerdict::decide(false, true, true, false),
        );
        $this->assertEquals(
            new AdminViewModeStartupDecision(false, writeFile: true, conflict: true),
            AdminViewModeStartupVerdict::decide(false, true, false, true),
        );
    }

    public function testBothHalvesWithTheVariableOffWriteNothing(): void
    {
        $this->assertEquals(new AdminViewModeStartupDecision(false), AdminViewModeStartupVerdict::decide(false, false, true, true));
    }

    public function testBothHalvesWithTheVariableOnReportTheConflictAndWriteNothing(): void
    {
        $this->assertEquals(
            new AdminViewModeStartupDecision(false, conflict: true),
            AdminViewModeStartupVerdict::decide(false, true, true, true),
        );
    }
}

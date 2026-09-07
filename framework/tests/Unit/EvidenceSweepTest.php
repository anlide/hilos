<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/evidence-sweep.php';

/**
 * The parts of the evidence sweep that answer without a filesystem: which names in the
 * log directory and in the artifact directory belong to no step of the manifest, and
 * the section a run prints about what it swept.
 *
 * Walking the directories and deleting what was picked is deliberately out of scope —
 * the same boundary {@see StepArtifactsTest} draws. The file under test is a plain
 * script rather than a class, so it is required by path, the same way
 * `scripts/run-test-suite.php` requires it; the runner itself is not loaded here,
 * because requiring it would start a whole test run.
 */
final class EvidenceSweepTest extends TestCase
{
    /** Two step ids, standing in for the manifest the sweep is judged against. */
    private const array KNOWN = ['framework', 'chat-e2e'];

    /** A step still in the manifest keeps the log of its last run. */
    public function testKeepsTheLogOfAStepStillInTheManifest(): void
    {
        $this->assertSame([], staleLogNames(['framework.log'], self::KNOWN));
    }

    /**
     * The log of a step deleted from the manifest is what the sweep exists for: nothing
     * else would ever remove it, and a grep across the directory reads it as fresh.
     */
    public function testSweepsTheLogOfAStepThatLeftTheManifest(): void
    {
        $this->assertSame(['simple-todo-e2e.log'], staleLogNames(['simple-todo-e2e.log'], self::KNOWN));
    }

    /**
     * The rc ledger and the artifact subdirectory sit inside the log directory by
     * default, and the suffix is what keeps them out of reach rather than a list of
     * exceptions that would have to grow with them.
     */
    public function testIgnoresTheLedgerAndTheArtifactDirectory(): void
    {
        $this->assertSame([], staleLogNames(['rc', 'artifacts', '.', '..'], self::KNOWN));
    }

    /** A step still in the manifest keeps the snapshot of its last run. */
    public function testKeepsTheArtifactTreeOfAKnownStep(): void
    {
        $this->assertSame([], staleArtifactNames(['chat-e2e'], self::KNOWN));
    }

    /** A step that left the manifest leaves both halves of its evidence, and both go. */
    public function testSweepsTheArtifactTreeOfAStepThatLeft(): void
    {
        $this->assertSame(['simple-todo-e2e'], staleArtifactNames(['simple-todo-e2e', '.', '..'], self::KNOWN));
    }

    /** A run that swept nothing writes nothing, so a green log keeps the text it had. */
    public function testPrintsNothingWhenNothingWasSwept(): void
    {
        $this->assertSame('', sweptEvidenceSection([]));
    }

    /** One line per step, naming what that step lost, however many halves that was. */
    public function testNamesEachSweptStepAndWhatItLost(): void
    {
        $section = sweptEvidenceSection([
            'simple-todo-e2e' => ['log', 'artifacts'],
            'todo-check' => ['log'],
        ]);

        $this->assertSame(
            "=== swept: 2 step(s) no longer in the manifest ===\n"
            . "  simple-todo-e2e      log, artifacts\n"
            . "  todo-check           log\n",
            $section,
        );
    }
}

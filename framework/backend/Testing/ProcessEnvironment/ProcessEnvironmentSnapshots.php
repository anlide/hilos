<?php

declare(strict_types=1);

namespace Hilos\Testing\ProcessEnvironment;

use Hilos\Core\Exception\LogicException;

/**
 * Remembers the process environment a suite or a test found and puts it back.
 *
 * Each open suite has one snapshot on a stack. The running test has one slot.
 */
final class ProcessEnvironmentSnapshots
{
    /** @var list<array<string, string>> One snapshot per suite still open, oldest first */
    private array $suiteSnapshots = [];

    /** @var ?array<string, string> Snapshot taken at openTest(), null when no test is open */
    private ?array $testSnapshot = null;

    /**
     * Pushes a snapshot of the environment the suite found.
     *
     * @throws LogicException When the process environment cannot be read
     */
    public function openSuite(): void
    {
        $this->suiteSnapshots[] = self::capture();
    }

    /**
     * Closes an occupied test slot, then restores the suite that just finished.
     * An empty stack does nothing further.
     *
     * @throws LogicException When the process environment cannot be read
     */
    public function closeSuite(): void
    {
        $this->closeTest();
        $snapshot = array_pop($this->suiteSnapshots);
        if (!is_array($snapshot)) {
            return;
        }
        self::restore($snapshot);
    }

    /**
     * Closes an occupied test slot, then records the environment this test found.
     *
     * @throws LogicException When the process environment cannot be read
     */
    public function openTest(): void
    {
        $this->closeTest();
        $this->testSnapshot = self::capture();
    }

    /**
     * Restores the open test's snapshot and clears the slot.
     * An empty slot does nothing.
     *
     * @throws LogicException When the process environment cannot be read
     */
    public function closeTest(): void
    {
        if ($this->testSnapshot === null) {
            return;
        }
        self::restore($this->testSnapshot);
        $this->testSnapshot = null;
    }

    /**
     * Reads every name the process currently exposes.
     *
     * @return array<string, string> Names and values getenv() reports
     * @throws LogicException When getenv() does not return that array
     */
    private static function capture(): array
    {
        $environment = getenv();
        if (!is_array($environment)) {
            throw new LogicException('The process environment could not be read');
        }

        return $environment;
    }

    /**
     * Puts the process environment back to one snapshot.
     * A stored name is written back; a name only in the live environment is removed.
     *
     * @param array<string, string> $snapshot Names and values to return to
     * @throws LogicException When the process environment cannot be read
     */
    private static function restore(array $snapshot): void
    {
        $current = self::capture();
        foreach (array_keys($current) as $name) {
            if (!array_key_exists($name, $snapshot)) {
                putenv((string) $name);
            }
        }
        foreach ($snapshot as $name => $value) {
            if (!array_key_exists($name, $current) || $current[$name] !== $value) {
                putenv((string) $name . '=' . $value);
            }
        }
    }
}

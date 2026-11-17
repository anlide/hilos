<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use Closure;
use PHPUnit\Event\Event;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\TestSuite\Finished as SuiteFinished;
use PHPUnit\Event\TestSuite\Started as SuiteStarted;
use PHPUnit\Event\Tracer\Tracer;
use Throwable;

/** Keeps only the active test and class; STDERR is already captured in the step's live log. */
final class TestProgress implements Tracer
{
    public const string PREFIX = 'hilos-test-progress: ';

    private readonly Closure $write;
    private readonly int $processStartedNs;

    /** @var array<string, int> At most one active test and one active class, in monotonic nanoseconds. */
    private array $started = [];

    /** @param Closure(string): void|null $write Captured diagnostic sink, STDERR by default */
    public function __construct(?Closure $write = null)
    {
        $this->processStartedNs = hrtime(true);
        $this->write = $write ?? static function (string $line): void {
            // warning-suppressed: a closed diagnostic stream loses the record without affecting PHPUnit
            $written = @fwrite(STDERR, $line);
            if ($written === false) {
                return;
            }
        };
    }

    /**
     * Observes lifecycle events without changing PHPUnit outcomes or the process alarm.
     *
     * @param Event $event Original PHPUnit event
     * @throws TestTimeLimitExceeded When the active alarm interrupts an observation
     */
    public function trace(Event $event): void
    {
        try {
            if ($event instanceof PreparationStarted) {
                $this->record('test', $event->test()->id(), true);
            } elseif ($event instanceof Finished) {
                $this->record('test', $event->test()->id(), false);
            } elseif ($event instanceof SuiteStarted && $event->testSuite()->isForTestClass()) {
                $this->record('class', $event->testSuite()->name(), true);
            } elseif ($event instanceof SuiteFinished && $event->testSuite()->isForTestClass()) {
                $this->record('class', $event->testSuite()->name(), false);
            }
        } catch (TestTimeLimitExceeded $error) {
            throw $error;
        } catch (Throwable) {
            // A refused write or an incomplete observation is diagnostic loss only.
        }
    }

    /**
     * @param string $kind Test or class boundary
     * @param string $id PHPUnit identity, including the data set when present
     * @param bool $begin Whether this boundary opens a span
     */
    private function record(string $kind, string $id, bool $begin): void
    {
        $at = hrtime(true);
        $since = $this->started[$kind] ?? null;
        if ($begin) {
            $this->started[$kind] = $at;
        } else {
            unset($this->started[$kind]);
        }
        $line = json_encode([
            'pid' => getmypid(),
            'processStartedNs' => $this->processStartedNs,
            'wall' => microtime(true),
            'monotonicNs' => $at,
            'kind' => $kind,
            'id' => $id,
            'edge' => $begin ? 'begin' : 'end',
            'durationNs' => $begin || $since === null ? null : $at - $since,
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            ($this->write)(self::PREFIX . $line . PHP_EOL);
        }
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Testing;

use Hilos\Testing\TimeLimit\TestProgress;
use Hilos\Testing\TimeLimit\TestTimeLimitExceeded;
use PHPUnit\Event\Code\TestMethodBuilder;
use PHPUnit\Event\Telemetry\Duration;
use PHPUnit\Event\Telemetry\Info;
use PHPUnit\Event\Telemetry\MemoryUsage;
use PHPUnit\Event\Telemetry\Php83GarbageCollectorStatusProvider;
use PHPUnit\Event\Telemetry\System;
use PHPUnit\Event\Telemetry\SystemMemoryMeter;
use PHPUnit\Event\Telemetry\SystemStopWatch;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TestProgressTest extends TestCase
{
    /** A refused begin write cannot escape into PHPUnit or prevent the following end observation. */
    public function testFailedSinkDoesNotEscapeOrLoseTheActiveSpan(): void
    {
        $attempts = 0;
        $lines = [];
        $progress = new TestProgress(static function (string $line) use (&$attempts, &$lines): void {
            if ($attempts++ === 0) {
                throw new RuntimeException('Diagnostic sink refused the write');
            }
            $lines[] = $line;
        });
        $test = TestMethodBuilder::fromTestCase($this);
        $handler = pcntl_signal_get_handler(SIGALRM);
        $progress->trace(new PreparationStarted(self::telemetry(), $test));
        $progress->trace(new Finished(self::telemetry(), $test, 0));

        self::assertSame($handler, pcntl_signal_get_handler(SIGALRM));
        self::assertCount(1, $lines);
        $end = json_decode(substr($lines[0], strlen(TestProgress::PREFIX)), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($test->id(), $end['id']);
        self::assertSame('end', $end['edge']);
        self::assertIsInt($end['durationNs']);
        self::assertGreaterThanOrEqual(0, $end['durationNs']);
    }

    /** An alarm raised during a diagnostic write must leave the tracer with its identity intact. */
    public function testSinkTimeLimitExceptionEscapesUnchanged(): void
    {
        $alarm = new TestTimeLimitExceeded('Test time limit exceeded during diagnostic output');
        $progress = new TestProgress(static function (string $line) use ($alarm): void {
            throw $alarm;
        });
        $test = TestMethodBuilder::fromTestCase($this);

        try {
            $progress->trace(new PreparationStarted(self::telemetry(), $test));
        } catch (TestTimeLimitExceeded $error) {
            self::assertSame($alarm, $error);

            return;
        }

        self::fail('The diagnostic tracer swallowed the time-limit exception');
    }

    /** @return Info PHPUnit telemetry for an in-process lifecycle observation */
    private static function telemetry(): Info
    {
        $snapshot = new System(new SystemStopWatch(), new SystemMemoryMeter(), new Php83GarbageCollectorStatusProvider());

        return new Info(
            $snapshot->snapshot(),
            Duration::fromSecondsAndNanoseconds(0, 0),
            MemoryUsage::fromBytes(0),
            Duration::fromSecondsAndNanoseconds(0, 0),
            MemoryUsage::fromBytes(0),
        );
    }
}

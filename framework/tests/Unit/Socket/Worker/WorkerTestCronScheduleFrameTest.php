<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Core\Daemon\Cron\CronScheduleTestOperation;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\DTO\WorkerTestCronScheduleDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronScheduleResultDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pause requests and acknowledgements keep their complete contract across worker framing. */
final class WorkerTestCronScheduleFrameTest extends TestCase
{
    public function testBothDirectionsRoundtripWithTheirLiteralWireShape(): void
    {
        foreach (CronScheduleTestOperation::cases() as $operation) {
            $request = new WorkerTestCronScheduleDTO('request-1', 'mailer', 'nightly/digest', $operation, 'pause-1');
            self::assertSame([
                'type' => 'test_cron_schedule', 'correlationId' => 'request-1', 'agentId' => 'mailer',
                'ruleName' => 'nightly/digest', 'operation' => $operation->value, 'pauseId' => 'pause-1',
            ], $request->toArray());
            $restored = WorkerDTO::factoryWorkerDTO($request->toJson());
            self::assertEquals($request, $restored);
            self::assertSame('test_cron_schedule', $restored->getType());
        }
        self::assertSame(['pause', 'cancel_pause'], array_column(CronScheduleTestOperation::cases(), 'value'));
        foreach ([null, 'No pause pause-1'] as $error) {
            $ack = new WorkerTestCronScheduleResultDTO('request-1', $error === null, $error);
            self::assertSame([
                'type' => 'test_cron_schedule_result', 'correlationId' => 'request-1', 'ok' => $error === null, 'error' => $error,
            ], $ack->toArray());
            $restored = WorkerDTO::factoryWorkerDTO($ack->toJson());
            self::assertEquals($ack, $restored);
            self::assertSame('test_cron_schedule_result', $restored->getType());
        }
    }

    /** @param array<string, mixed> $frame Malformed request or acknowledgement */
    #[DataProvider('malformedFrames')]
    public function testMalformedFramesAreRefused(array $frame): void
    {
        $this->expectException(InvalidFormatException::class);
        WorkerDTO::factoryWorkerDTO(json_encode($frame, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{array<string, mixed>}> Incomplete, mistyped and contradictory frames */
    public static function malformedFrames(): iterable
    {
        $request = [
            'type' => 'test_cron_schedule', 'correlationId' => 'c-1', 'agentId' => 'mailer',
            'ruleName' => 'nightly', 'operation' => 'pause', 'pauseId' => 'pause-1',
        ];
        foreach (['correlationId', 'agentId', 'ruleName', 'operation', 'pauseId'] as $field) {
            $missing = $request;
            unset($missing[$field]);
            yield "request missing $field" => [$missing];
            foreach (['', null, 1, true, [], 1.5] as $index => $value) {
                yield "request $field $index" => [array_replace($request, [$field => $value])];
            }
        }
        yield 'unknown operation' => [array_replace($request, ['operation' => 'resume'])];
        $ack = ['type' => 'test_cron_schedule_result', 'correlationId' => 'c-1', 'ok' => true, 'error' => null];
        foreach (['correlationId', 'ok', 'error'] as $field) {
            $missing = $ack;
            unset($missing[$field]);
            yield "ack missing $field" => [$missing];
        }
        foreach (['', null, 1, true, [], 1.5] as $index => $value) {
            yield "ack correlation $index" => [array_replace($ack, ['correlationId' => $value])];
        }
        foreach ([null, 0, 1, 'true', [], 1.0] as $index => $value) {
            yield "ack ok type $index" => [array_replace($ack, ['ok' => $value])];
        }
        foreach (['', 'failure', false, 0, []] as $index => $value) {
            yield "success with error $index" => [array_replace($ack, ['error' => $value])];
        }
        foreach ([null, '', false, 0, []] as $index => $value) {
            yield "failure without reason $index" => [array_replace($ack, ['ok' => false, 'error' => $value])];
        }
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\DTO\WorkerTestOrmForgetDTO;
use Hilos\Socket\Worker\DTO\WorkerTestOrmForgottenDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** No incomplete or mistyped worker acknowledgement may turn into success. */
final class WorkerTestOrmForgetFrameTest extends TestCase
{
    /** The worker registry restores both requests and each complete outcome. */
    public function testBothDirectionsRoundtripThroughTheWorkerFactory(): void
    {
        foreach ([
            new WorkerTestOrmForgetDTO('request-1'),
            new WorkerTestOrmForgottenDTO('request-1', true, null),
            new WorkerTestOrmForgottenDTO('request-1', false, 'Transaction still open'),
        ] as $frame) {
            $restored = WorkerDTO::factoryWorkerDTO($frame->toJson());
            self::assertSame($frame::class, $restored::class);
            self::assertSame($frame->toArray(), $restored->toArray());
            self::assertSame($frame->getType(), $restored->getType());
        }
    }

    /** @param array<string, mixed> $frame Broken wire frame */
    #[DataProvider('malformedFrames')]
    public function testMalformedFramesAreRefused(array $frame): void
    {
        $this->expectException(InvalidFormatException::class);
        WorkerDTO::factoryWorkerDTO(json_encode($frame, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{array<string, mixed>}> Broken request and acknowledgement shapes */
    public static function malformedFrames(): iterable
    {
        foreach (['test_orm_forget', 'test_orm_forgotten'] as $type) {
            $valid = ['type' => $type, 'correlationId' => 'request-1', 'ok' => true, 'error' => null];
            $missing = $valid;
            unset($missing['correlationId']);
            yield "$type missing correlation" => [$missing];
            foreach (['', null, 1, true, [], 1.5] as $index => $value) {
                yield "$type correlation $index" => [array_replace($valid, ['correlationId' => $value])];
            }
        }
        $valid = ['type' => 'test_orm_forgotten', 'correlationId' => 'request-1', 'ok' => true, 'error' => null];
        foreach (['ok', 'error'] as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield "missing $key" => [$missing];
        }
        foreach ([null, 0, 1, 'true', 'false', [], 1.0] as $index => $value) {
            yield "ok type $index" => [array_replace($valid, ['ok' => $value])];
        }
        foreach (['', 'failure', false, 0, []] as $index => $value) {
            yield "success with error $index" => [array_replace($valid, ['error' => $value])];
        }
        foreach ([null, '', false, 0, []] as $index => $value) {
            yield "failure without reason $index" => [array_replace($valid, ['ok' => false, 'error' => $value])];
        }
    }

    /** A locally built negative ACK owes the same reason as a received one. */
    public function testConstructorAlsoRefusesAnInconsistentOutcome(): void
    {
        $this->expectException(InvalidFormatException::class);
        new WorkerTestOrmForgottenDTO('request-1', false, null);
    }
}

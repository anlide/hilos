<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\DTO\WorkerTestCronFireDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronFiredDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** No incomplete or mistyped cron fire frame may turn into an armed rule or a fired one. */
final class WorkerTestCronFireFrameTest extends TestCase
{
    /** The worker registry restores the request and each complete outcome. */
    public function testBothDirectionsRoundtripThroughTheWorkerFactory(): void
    {
        foreach ([
            new WorkerTestCronFireDTO('request-1', 'mailer', 'nightly-digest'),
            new WorkerTestCronFiredDTO('request-1', true, 1_767_225_600_000, null),
            new WorkerTestCronFiredDTO('request-1', false, null, 'Agent mailer holds no cron rule nightly-digest.'),
        ] as $frame) {
            $restored = WorkerDTO::factoryWorkerDTO($frame->toJson());
            self::assertSame($frame::class, $restored::class);
            self::assertSame($frame->toArray(), $restored->toArray());
            self::assertSame($frame->getType(), $restored->getType());
        }
    }

    /** The wire names of both frames and of every field are fixed. */
    public function testTheWireCarriesTheDeclaredNames(): void
    {
        self::assertSame(
            ['type' => 'test_cron_fire', 'correlationId' => 'request-1', 'agentId' => 'mailer', 'ruleName' => 'nightly-digest'],
            new WorkerTestCronFireDTO('request-1', 'mailer', 'nightly-digest')->toArray(),
        );
        self::assertSame(
            ['type' => 'test_cron_fired', 'correlationId' => 'request-1', 'ok' => true, 'firedAt' => 1_767_225_600_000, 'error' => null],
            new WorkerTestCronFiredDTO('request-1', true, 1_767_225_600_000, null)->toArray(),
        );
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
        $request = ['type' => 'test_cron_fire', 'correlationId' => 'request-1', 'agentId' => 'mailer', 'ruleName' => 'nightly'];
        foreach (['correlationId', 'agentId', 'ruleName'] as $key) {
            $missing = $request;
            unset($missing[$key]);
            yield "request missing $key" => [$missing];
            foreach (['', null, 1, true, [], 1.5] as $index => $value) {
                yield "request $key $index" => [array_replace($request, [$key => $value])];
            }
        }

        $ack = ['type' => 'test_cron_fired', 'correlationId' => 'request-1', 'ok' => true, 'firedAt' => 1_000, 'error' => null];
        foreach (['correlationId', 'ok', 'firedAt', 'error'] as $key) {
            $missing = $ack;
            unset($missing[$key]);
            yield "ack missing $key" => [$missing];
        }
        foreach (['', null, 1, true, [], 1.5] as $index => $value) {
            yield "ack correlation $index" => [array_replace($ack, ['correlationId' => $value])];
        }
        foreach ([null, 0, 1, 'true', 'false', [], 1.0] as $index => $value) {
            yield "ok type $index" => [array_replace($ack, ['ok' => $value])];
        }
        foreach (['1000', 1000.5, true, []] as $index => $value) {
            yield "firedAt type $index" => [array_replace($ack, ['firedAt' => $value])];
        }
        yield 'success without moment' => [array_replace($ack, ['firedAt' => null])];
        foreach (['', 'failure', false, 0, []] as $index => $value) {
            yield "success with error $index" => [array_replace($ack, ['error' => $value])];
        }
        $failure = array_replace($ack, ['ok' => false, 'firedAt' => null, 'error' => 'Agent mailer stopped before the rule fired.']);
        yield 'failure with moment' => [array_replace($failure, ['firedAt' => 1_000])];
        foreach ([null, '', false, 0, []] as $index => $value) {
            yield "failure without reason $index" => [array_replace($failure, ['error' => $value])];
        }
    }

    /** A locally built outcome owes the same consistency as a received one. */
    public function testConstructorAlsoRefusesAnInconsistentOutcome(): void
    {
        $this->expectException(InvalidFormatException::class);
        new WorkerTestCronFiredDTO('request-1', true, null, null);
    }
}

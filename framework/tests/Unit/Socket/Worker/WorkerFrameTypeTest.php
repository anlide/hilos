<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Constants\AgentConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\MalformedInput;
use Hilos\Core\Exception\NonArrayPayloadException;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\Exception\UnknownWorkerMessageTypeException;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Pins how the worker channel refuses a frame that names no type (HIL-549), and that
 * every way the frame can be refused says the input could not be parsed (HIL-601).
 *
 * The type guard used to read the raw value through `?? ''` and compare it to the empty
 * string, so a frame whose `type` was an array walked past it, reached the `match`
 * default and was interpolated into the refusal message — an "Array to string
 * conversion" warning inside the constructor of the very exception meant to report
 * the frame. It is refused by the same exception now, for its own reason.
 *
 * The refusals used to be the generic invalid-argument exception, which the marker
 * cannot be put on: the same class reports a caller passing nonsense from inside the
 * node. Reading the worker channel is reading the wire, so each refusal here now
 * carries {@see MalformedInput} and reaches the log at the level meant for input.
 */
final class WorkerFrameTypeTest extends TestCase
{
    public function testFrameThatDoesNotDecodeIsRefusedAsUnparsedJson(): void
    {
        $this->expectException(InvalidJsonException::class);

        WorkerDTO::factoryWorkerDTO('{"type":');
    }

    public function testFrameDecodingIntoANumberIsRefusedForItsShape(): void
    {
        $this->expectException(NonArrayPayloadException::class);

        WorkerDTO::factoryWorkerDTO('123');
    }

    public function testFrameDecodingIntoAStringIsRefusedForItsShape(): void
    {
        $this->expectException(NonArrayPayloadException::class);

        WorkerDTO::factoryWorkerDTO('"text"');
    }

    public function testFrameWithNoTypeIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        WorkerDTO::factoryWorkerDTO('{"payload":{}}');
    }

    public function testFrameWithAnEmptyTypeIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        WorkerDTO::factoryWorkerDTO('{"type":""}');
    }

    public function testFrameWithANonStringTypeIsRefusedWithoutAConversionWarning(): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            throw new RuntimeException("PHP raised: {$message}");
        });

        try {
            WorkerDTO::factoryWorkerDTO('{"type":["agent_start"]}');
            $this->fail('A frame whose type is not a name must not be accepted.');
        } catch (InvalidFormatException $e) {
            $this->assertStringContainsString('Message type is missing', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testFrameNamingAnUnknownTypeIsRefusedByItsOwnException(): void
    {
        $this->expectException(UnknownWorkerMessageTypeException::class);
        $this->expectExceptionMessage('no_such_worker_frame');

        WorkerDTO::factoryWorkerDTO('{"type":"no_such_worker_frame"}');
    }

    /**
     * Every refusal the factory makes is about input that could not be parsed, so the
     * guard writing the log can ask the failure itself instead of matching class names.
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedFrameProvider(): iterable
    {
        yield 'undecodable' => ['{"type":'];
        yield 'not an array' => ['123'];
        yield 'no type' => ['{"payload":{}}'];
        yield 'unknown type' => ['{"type":"no_such_worker_frame"}'];
        yield 'field the DTO needs is missing' => ['{"type":"' . AgentStartDTO::MESSAGE_TYPE . '"}'];
    }

    /**
     * @param string $json Frame the factory must refuse
     */
    #[DataProvider('refusedFrameProvider')]
    public function testEveryRefusalCarriesTheMalformedInputMarker(string $json): void
    {
        try {
            WorkerDTO::factoryWorkerDTO($json);
            $this->fail('The factory must refuse this frame.');
        } catch (Throwable $failure) {
            $this->assertInstanceOf(MalformedInput::class, $failure);
        }
    }

    public function testFrameOfAKnownTypeMissingItsFieldIsRefusedInsteadOfBuiltWithZeros(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(AgentConstants::FIELD_AGENT_ID);

        WorkerDTO::factoryWorkerDTO('{"type":"' . AgentStartDTO::MESSAGE_TYPE . '"}');
    }

    public function testRegistrationWithALoweredFlagIsAcceptedAsTheAnswerItIs(): void
    {
        $frame = WorkerDTO::factoryWorkerDTO(
            (new WorkerRegisterDTO(workerIndex: 0, monopolistic: false))->toJson(),
        );

        $this->assertInstanceOf(WorkerRegisterDTO::class, $frame);
        $this->assertSame(0, $frame->workerIndex);
        $this->assertFalse($frame->monopolistic);
    }

    public function testRegistrationWithoutItsMonopolisticFlagIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WorkerRegisterDTO::MONOPOLISTIC);

        WorkerDTO::factoryWorkerDTO(
            '{"type":"' . WorkerRegisterDTO::MESSAGE_TYPE . '","' . WorkerRegisterDTO::WORKER_INDEX . '":1}',
        );
    }
}

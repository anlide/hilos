<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\WebSocket\Delivery\DeliveryFrame;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class DeliveryFrameTest extends TestCase
{
    public function testPreparedJsonIsRetainedByteForByteWithMetadataAndJsonTypes(): void
    {
        $json = '{ "type":"project_signal", "data":{"map":{},"list":[],"number":1.0,"id":"1"},'
            . '"requestId":"r-1","outcome":{"message":"Done"},"time":123.5 }';
        $frame = DeliveryFrame::fromJson($json);
        self::assertSame($json, $frame->json);
        self::assertSame('project_signal', $frame->getType());
        $wire = $frame->getEnvelope();
        self::assertInstanceOf(stdClass::class, $wire->data->map);
        self::assertSame([], $wire->data->list);
        self::assertSame(1.0, $wire->data->number);
        self::assertSame('1', $wire->data->id);
        self::assertSame('r-1', $wire->requestId);
        self::assertSame('Done', $wire->outcome->message);
        self::assertSame(123.5, $wire->time);
        self::assertTrue($frame->isEvent());
    }

    public function testOneSharedFrameIsDetachedFromBothInputAndReturnedNestedObjects(): void
    {
        $input = (object) [
            'type' => 'page_response',
            'data' => (object) ['page' => 'p', 'payload' => (object) [
                'entities' => (object) ['some_entity' => (object) ['id' => 42, 'some_value' => 1]],
                'data' => (object) ['values' => [(object) ['map' => new stdClass()]]],
            ]],
        ];
        $frame = DeliveryFrame::fromEnvelope($input);
        $json = $frame->json;
        $input->data->payload->entities->some_entity->some_value = 90;
        $consumerA = $frame->getEnvelope();
        $consumerA->data->payload->entities->some_entity->some_value = 99;
        $consumerA->data->payload->data->values[0]->map->changed = true;
        $consumerB = $frame->getEnvelope();
        self::assertSame(1, $consumerB->data->payload->entities->some_entity->some_value);
        self::assertEquals(new stdClass(), $consumerB->data->payload->data->values[0]->map);
        self::assertSame($json, $frame->json);
        self::assertFalse($frame->isEvent());
    }

    public function testEnvelopeFactoryPreservesEmptyContainersNullsAndFloatTypes(): void
    {
        $frame = DeliveryFrame::fromEnvelope([
            'type' => 'project_signal',
            'data' => ['map' => new stdClass(), 'list' => [], 'float' => 1.0, 'null' => null, 'bool' => false],
        ]);
        self::assertSame(
            '{"type":"project_signal","data":{"map":{},"list":[],"float":1.0,"null":null,"bool":false}}',
            $frame->json,
        );
    }

    public function testAnUnencodableEnvelopeIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        DeliveryFrame::fromEnvelope(['type' => 'project_signal', 'data' => ['invalid' => INF]]);
    }

    /** @return iterable<string, array{string}> Compatibility boundaries */
    public static function opaqueText(): iterable
    {
        yield 'plain text' => ['legacy text'];
        yield 'malformed json' => ['{"type":'];
        yield 'list' => ['[]'];
        yield 'scalar' => ['42'];
        yield 'missing data' => ['{"type":"legacy"}'];
        yield 'non-string type' => ['{"type":42,"data":{}}'];
    }

    /** @param string $json Public sendFrame compatibility text */
    #[DataProvider('opaqueText')]
    public function testNonEnvelopeTextRetainsItsBytesAndIsNotADomainSignal(string $json): void
    {
        $frame = DeliveryFrame::fromJson($json);
        self::assertSame($json, $frame->json);
        self::assertNull($frame->getType());
        self::assertNull($frame->getEnvelope());
        self::assertFalse($frame->isUrgent());
    }

    /** @return iterable<string, array{array<string, mixed>, bool, bool}> Response metadata cases */
    public static function metadata(): iterable
    {
        yield 'ordinary patch' => [['type' => 'page_response', 'data' => ['page' => 'p']], false, false];
        yield 'envelope correlation' => [
            ['type' => 'page_response', 'data' => ['page' => 'p'], 'requestId' => 'r'], true, false,
        ];
        yield 'envelope outcome' => [['type' => 'page_response', 'data' => ['page' => 'p'], 'outcome' => null], true, false];
        yield 'body correlation' => [['type' => 'page_response', 'data' => ['page' => 'p', 'requestId' => 'r']], true, false];
        yield 'body outcome' => [['type' => 'page_response', 'data' => ['page' => 'p', 'outcome' => false]], true, false];
        yield 'time alone' => [['type' => 'page_response', 'data' => ['page' => 'p'], 'time' => 12], false, false];
        yield 'correlated success' => [['type' => 'action_success', 'data' => [], 'requestId' => 'r'], true, true];
        yield 'correlated error' => [['type' => 'action_error', 'data' => ['requestId' => 'r']], true, true];
        yield 'uncorrelated action' => [['type' => 'action_success', 'data' => []], false, false];
        yield 'table window' => [['type' => 'table_window', 'data' => []], false, true];
        yield 'table refusal' => [['type' => 'table_window_refused', 'data' => []], false, true];
    }

    /**
     * @param array<string, mixed> $wire Boundary envelope
     * @param bool $event Expected independence from state coalescing
     * @param bool $urgent Expected response urgency
     */
    #[DataProvider('metadata')]
    public function testMetadataAndCorrelatedRepliesAreExposedWithoutProtocolParsing(array $wire, bool $event, bool $urgent): void
    {
        $frame = DeliveryFrame::fromEnvelope($wire);
        self::assertSame($event, $frame->isEvent());
        self::assertSame($urgent, $frame->isUrgent());
    }

    public function testCallerMarksFullResponsesAndMatchingCompletionsExplicitly(): void
    {
        $frame = DeliveryFrame::fromEnvelope(['type' => 'page_response', 'data' => ['page' => 'p']], subscriptionResponse: true);
        self::assertTrue($frame->subscriptionResponse);
        self::assertTrue($frame->isEvent());
        self::assertFalse($frame->isUrgent());
        $completion = DeliveryFrame::fromEnvelope(['type' => 'project_signal', 'data' => []], urgent: true);
        self::assertTrue($completion->isUrgent());
    }
}

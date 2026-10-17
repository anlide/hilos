<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PageResubscribeSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageResubscribeSignalDataTest extends TestCase
{
    public function testTypedEnvelopeRoundtripRetainsTheExactTargetAndPayload(): void
    {
        $signal = new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalConstants::PAGE_RESUBSCRIBE),
            new WebSocketSignalData(new PageResubscribeSignalData('room', ['roomId' => '42']), 'tab'),
        );
        foreach ([$signal->toArray(), json_decode(json_encode($signal->toArray()), true)] as $serialized) {
            $restored = SignalDTO::fromArray($serialized);
            $this->assertInstanceOf(WebSocketSignalData::class, $restored->data);
            $this->assertSame('tab', $restored->data->targetAcceptKey);
            $this->assertInstanceOf(PageResubscribeSignalData::class, $restored->data->data);
            $this->assertSame('room', $restored->data->data->page);
            $this->assertSame(['roomId' => '42'], $restored->data->data->params);
        }
    }

    public function testEmptyParamsRemainAMapOnTheWireAndRoundtripInProcess(): void
    {
        $data = new PageResubscribeSignalData('picture', []);
        $this->assertSame('{"page":"picture","params":{}}', json_encode($data->toArray()));
        $this->assertSame([], PageResubscribeSignalData::fromArray($data->toArray())->params);
        $this->assertSame([], PageResubscribeSignalData::fromArray(json_decode(json_encode($data->toArray()), true))->params);
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsAnAbsentOrMalformedRequiredField(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        PageResubscribeSignalData::fromArray($payload);
    }

    public static function invalidPayloads(): iterable
    {
        yield 'no page' => [['params' => []]];
        yield 'empty page' => [['page' => '', 'params' => []]];
        yield 'wrong page' => [['page' => 42, 'params' => []]];
        yield 'no params' => [['page' => 'picture']];
        yield 'null params' => [['page' => 'picture', 'params' => null]];
        yield 'scalar params' => [['page' => 'picture', 'params' => '42']];
        yield 'list params' => [['page' => 'picture', 'params' => ['42']]];
    }
}

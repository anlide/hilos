<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Router\DTO\ActionSuccessSignalData;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketEnvelopeAware;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ActionSuccessSignalData}.
 *
 * These assertions pin down the envelope contract that
 * {@see DaemonManager::mergeEnvelopeMetadata()} relies on:
 * a DTO that returns `outcome='success'` and an optional `message`
 * merged into the inner payload.
 */
final class ActionSuccessSignalDataTest extends TestCase
{
    public function testImplementsRequiredInterfaces(): void
    {
        $dto = new ActionSuccessSignalData();

        $this->assertInstanceOf(SignalDataInterface::class, $dto);
        $this->assertInstanceOf(WebSocketEnvelopeAware::class, $dto);
    }

    public function testEnvelopeOutcomeIsSuccess(): void
    {
        $dto = new ActionSuccessSignalData();

        $this->assertSame('success', $dto->getEnvelopeOutcome());
    }

    public function testEnvelopeTimeDefaultsToNull(): void
    {
        $dto = new ActionSuccessSignalData();

        $this->assertNull($dto->getEnvelopeTime());
    }

    public function testEmptyPayloadByDefault(): void
    {
        $dto = new ActionSuccessSignalData();

        $this->assertSame([], $dto->toArray());
    }

    public function testCustomPayloadIsPreserved(): void
    {
        $dto = new ActionSuccessSignalData(['foo' => 'bar', 'n' => 42]);

        $this->assertSame(['foo' => 'bar', 'n' => 42], $dto->toArray());
    }

    public function testMessageIsMergedIntoPayload(): void
    {
        $dto = new ActionSuccessSignalData(message: 'Saved');

        $this->assertSame(['message' => 'Saved'], $dto->toArray());
    }

    public function testMessageIsMergedWithExistingData(): void
    {
        $dto = new ActionSuccessSignalData(['entityId' => 7], 'Renamed');

        $this->assertSame(['entityId' => 7, 'message' => 'Renamed'], $dto->toArray());
    }

    public function testMessageOmittedWhenNull(): void
    {
        $dto = new ActionSuccessSignalData(['x' => 1], null);

        $this->assertSame(['x' => 1], $dto->toArray());
        $this->assertArrayNotHasKey('message', $dto->toArray());
    }

    /**
     * Regression: worker → daemon IPC roundtrip must preserve the concrete
     * class. If {@see self::fromArray()} bails out, WebSocketSignalData's
     * deserializer falls back to generic SignalData, which no longer
     * implements WebSocketEnvelopeAware, and the outgoing WS frame loses the
     * `outcome='success'` envelope marker — causing the frontend router to
     * throw "Unhandled websocket signal".
     */
    public function testRoundtripPreservesConcreteTypeAndEnvelopeMarker(): void
    {
        $original = new ActionSuccessSignalData();

        $restored = ActionSuccessSignalData::fromArray($original->toArray());

        $this->assertInstanceOf(ActionSuccessSignalData::class, $restored);
        $this->assertInstanceOf(WebSocketEnvelopeAware::class, $restored);
        $this->assertSame('success', $restored->getEnvelopeOutcome());
        $this->assertSame([], $restored->toArray());
    }

    public function testRoundtripPreservesMessage(): void
    {
        $original = new ActionSuccessSignalData(message: 'Saved');

        $restored = ActionSuccessSignalData::fromArray($original->toArray());

        $this->assertSame('Saved', $restored->message);
        $this->assertSame(['message' => 'Saved'], $restored->toArray());
    }

    public function testRoundtripPreservesCustomPayload(): void
    {
        $original = new ActionSuccessSignalData(['entityId' => 42], 'Renamed');

        $restored = ActionSuccessSignalData::fromArray($original->toArray());

        $this->assertSame('Renamed', $restored->message);
        $this->assertSame(['entityId' => 42, 'message' => 'Renamed'], $restored->toArray());
    }
}

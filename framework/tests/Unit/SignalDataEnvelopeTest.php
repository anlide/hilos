<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataEnvelope;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the shared `{data, dataType}` envelope used by both signal payload wrappers.
 */
final class SignalDataEnvelopeTest extends TestCase
{
    private const string MISSING_CLASS = 'Hilos\Tests\Unit\EnvelopeTestMissingPayload';

    public function testEncodeCarriesDataOfPayloadWithoutBaseDto(): void
    {
        $encoded = SignalDataEnvelope::encode(new EnvelopeTestPlainPayload('kept'));

        $this->assertSame([
            SignalPayloadConstants::FIELD_DATA => ['value' => 'kept'],
            SignalPayloadConstants::FIELD_DATA_TYPE => EnvelopeTestPlainPayload::class,
        ], $encoded);
    }

    public function testRoundTripRestoresPayloadWithoutBaseDto(): void
    {
        $encoded = SignalDataEnvelope::encode(new EnvelopeTestPlainPayload('kept'));

        $decoded = SignalDataEnvelope::decode(
            $encoded[SignalPayloadConstants::FIELD_DATA],
            $encoded[SignalPayloadConstants::FIELD_DATA_TYPE],
        );

        $this->assertInstanceOf(EnvelopeTestPlainPayload::class, $decoded);
        $this->assertSame('kept', $decoded->value);
    }

    public function testDecodeFallsBackToSignalDataForUnknownType(): void
    {
        $decoded = SignalDataEnvelope::decode(['value' => 'kept'], self::MISSING_CLASS);

        $this->assertInstanceOf(SignalData::class, $decoded);
        $this->assertSame(['value' => 'kept'], $decoded->toArray());
    }

    public function testDecodeFallsBackWithAWarningWhenAKnownTypeRefusesThePayload(): void
    {
        ob_start();
        $decoded = SignalDataEnvelope::decode(['other' => 'kept'], EnvelopeTestRequiredFieldPayload::class);
        $logged = (string)ob_get_clean();

        $this->assertInstanceOf(SignalData::class, $decoded);
        $this->assertSame(['other' => 'kept'], $decoded->toArray());
        $this->assertStringContainsString(EnvelopeTestRequiredFieldPayload::class, $logged);
        $this->assertStringContainsString(InvalidFormatException::class, $logged);
        $this->assertStringContainsString('value', $logged);
    }

    public function testIncompleteWebSocketPayloadDegradesToSignalDataInsteadOfADtoOfStubs(): void
    {
        // The master-worker hop of a real signal DTO: before HIL-562 an incomplete
        // frame arrived as a WebSocketFrameSignalDTO addressed to the empty accept
        // key, which routes to no connection and reports nothing.
        ob_start();
        $decoded = SignalDataEnvelope::decode(
            [WebSocketFrameSignalDTO::ACCEPT_KEY => 'ak-1'],
            WebSocketFrameSignalDTO::class,
        );
        $logged = (string)ob_get_clean();

        $this->assertInstanceOf(SignalData::class, $decoded);
        $this->assertStringContainsString(WebSocketFrameSignalDTO::class, $logged);
        $this->assertStringContainsString(WebSocketFrameSignalDTO::PAYLOAD, $logged);
    }
}

/**
 * Signal payload reading its required field through the shared BaseDTO helper.
 */
final class EnvelopeTestRequiredFieldPayload extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $value Required payload value
     */
    public function __construct(public readonly string $value)
    {
    }

    /**
     * @return array<string, mixed> Wire payload
     */
    public function toArray(): array
    {
        return ['value' => $this->value];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored payload
     * @throws InvalidFormatException When the payload carries no value
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'value'));
    }
}

/**
 * Signal payload implementing the interface without extending BaseDTO.
 */
final class EnvelopeTestPlainPayload implements SignalDataInterface
{
    /**
     * @param string $value Payload value
     */
    public function __construct(public readonly string $value)
    {
    }

    /**
     * @return array<string, mixed> Wire payload
     */
    public function toArray(): array
    {
        return ['value' => $this->value];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored payload
     */
    public static function fromArray(array $data): static
    {
        return new static(is_string($data['value'] ?? null) ? $data['value'] : '');
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataEnvelope;
use Hilos\Core\Router\SignalDataInterface;
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

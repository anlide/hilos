<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;

/**
 * Shared `{data, dataType}` envelope for signal payload wrappers.
 *
 * Both WebSocketSignalData and AgentSignalData wrap an inner SignalDataInterface
 * payload and serialize it alongside its class name so the receiver can rebuild
 * the concrete type. This helper centralizes that encode/decode pair and its
 * SignalData fallback so the two wrappers do not duplicate it.
 */
final class SignalDataEnvelope
{
    /**
     * Builds the `{data, dataType}` envelope for an inner payload.
     *
     * @param SignalDataInterface $data Inner signal payload
     * @return array{data: array<string, mixed>, dataType: class-string} Envelope with payload and class name
     */
    public static function encode(SignalDataInterface $data): array
    {
        return [
            SignalPayloadConstants::FIELD_DATA => $data instanceof BaseDTO ? $data->toArray() : [],
            SignalPayloadConstants::FIELD_DATA_TYPE => get_class($data),
        ];
    }

    /**
     * Rebuilds the inner payload from a `{data, dataType}` envelope.
     *
     * Falls back to SignalData when the class is unknown, does not implement
     * SignalDataInterface, or fails to deserialize.
     *
     * @param array<string, mixed> $dataArray Inner payload array
     * @param ?string $dataType Inner payload class name, or null when absent
     * @return SignalDataInterface Deserialized inner payload, or SignalData fallback
     */
    public static function decode(array $dataArray, ?string $dataType): SignalDataInterface
    {
        if (is_string($dataType) && class_exists($dataType) && is_a($dataType, SignalDataInterface::class, true)) {
            try {
                return $dataType::fromArray($dataArray);
            } catch (\Throwable) {
                // Fall through to the SignalData fallback below.
            }
        }

        return new SignalData($dataArray);
    }
}

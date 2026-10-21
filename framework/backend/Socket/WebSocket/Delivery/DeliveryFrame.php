<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Delivery;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use JsonException;
use stdClass;

/** Immutable application text prepared once per broadcast and shared by recipient queues. */
final class DeliveryFrame
{
    public const string outcome = 'outcome';
    public const string time = 'time';

    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @param string $json Prepared text, reused unchanged until a payload is merged
     * @param ?stdClass $envelope Recognized application envelope, otherwise an opaque text boundary
     * @param bool $subscriptionResponse Full subscription answer, never a mergeable patch
     * @param bool $urgent Explicit server-side response correlation
     */
    private function __construct(
        public readonly string $json,
        private readonly ?stdClass $envelope,
        public readonly bool $subscriptionResponse,
        private readonly bool $urgent,
    ) {
    }

    /**
     * Arbitrary public sendFrame text remains an ordered compatibility boundary.
     * JSON is decoded once at this preparation boundary, never on queue insertion or release.
     *
     * @param string $json Prepared transport text
     * @param bool $subscriptionResponse Whether this is a complete initial page response
     * @param bool $urgent Whether the sender correlated a response to an accepted request
     * @return self Immutable prepared frame
     */
    public static function fromJson(string $json, bool $subscriptionResponse = false, bool $urgent = false): self
    {
        $envelope = json_decode($json);
        if (!$envelope instanceof stdClass
            || !isset($envelope->{SignalPayloadConstants::FIELD_TYPE})
            || !is_string($envelope->{SignalPayloadConstants::FIELD_TYPE})
            || !property_exists($envelope, SignalPayloadConstants::FIELD_DATA)
        ) {
            $envelope = null;
        }

        return new self($json, $envelope, $subscriptionResponse, $urgent);
    }

    /**
     * @param array<string, mixed>|stdClass $envelope Existing wire envelope; objects retain JSON map identity
     * @param bool $subscriptionResponse Whether this is a complete initial page response
     * @param bool $urgent Whether the sender correlated a response to an accepted request
     * @return self Prepared frame detached from the caller's mutable objects
     * @throws InvalidFormatException When the envelope cannot be encoded as JSON
     */
    public static function fromEnvelope(array|stdClass $envelope, bool $subscriptionResponse = false, bool $urgent = false): self
    {
        try {
            return self::fromJson(json_encode($envelope, self::JSON_FLAGS), $subscriptionResponse, $urgent);
        } catch (JsonException $exception) {
            throw new InvalidFormatException('Delivery envelope cannot be encoded', previous: $exception);
        }
    }

    /** @return ?string Signal name, or null for opaque text */
    public function getType(): ?string
    {
        return $this->envelope?->{SignalPayloadConstants::FIELD_TYPE};
    }

    /** @return ?stdClass Detached wire envelope, retaining empty maps and lists */
    public function getEnvelope(): ?stdClass
    {
        return self::copyValue($this->envelope);
    }

    /** @return bool Whether metadata makes this an independently observable event */
    public function isEvent(): bool
    {
        $data = $this->envelope?->{SignalPayloadConstants::FIELD_DATA};
        foreach ([SignalPayloadConstants::FIELD_REQUEST_ID, self::outcome] as $key) {
            if (($this->envelope !== null && property_exists($this->envelope, $key))
                || ($data instanceof stdClass && property_exists($data, $key))
            ) {
                return true;
            }
        }

        return $this->subscriptionResponse;
    }

    /** @return bool Whether this response starts a fresh immediate window */
    public function isUrgent(): bool
    {
        if ($this->urgent || in_array($this->getType(), [SignalTypeConstants::TABLE_WINDOW, SignalTypeConstants::TABLE_WINDOW_REFUSED], true)) {
            return true;
        }

        return in_array($this->getType(), [SignalConstants::ACTION_SUCCESS, SignalConstants::ACTION_ERROR], true)
            && $this->envelope !== null
            && (isset($this->envelope->{SignalPayloadConstants::FIELD_REQUEST_ID})
                || isset($this->envelope->{SignalPayloadConstants::FIELD_DATA}->{SignalPayloadConstants::FIELD_REQUEST_ID}));
    }

    /**
     * @param mixed $value JSON value copied without a serialization roundtrip
     * @return mixed Detached JSON value
     */
    private static function copyValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $copy = new stdClass();
            foreach (get_object_vars($value) as $key => $child) {
                $copy->{$key} = self::copyValue($child);
            }

            return $copy;
        }
        if (is_array($value)) {
            return array_map(self::copyValue(...), $value);
        }

        return $value;
    }
}

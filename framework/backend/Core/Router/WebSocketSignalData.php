<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;

/**
 * WebSocketSignalData - Signal data for WebSocket signals.
 *
 * Contains both the actual signal data and targeting metadata
 * (targetAcceptKey, targetSessionTokenHash, targetGroup, excludeAcceptKey).
 */
class WebSocketSignalData extends BaseDTO implements SignalDataInterface
{
    /** @var ?string Target connection accept key (user delivery), null when unaddressed */
    public readonly ?string $targetAcceptKey;

    /**
     * @var ?string Hash of the target session token (session delivery), null when unaddressed
     *
     * The accept key above names one socket; this names every socket of one browser, which is what
     * a delivery has to say when the tab it is answering may have been replaced by a reload.
     */
    public readonly ?string $targetSessionTokenHash;

    /** @var ?string Target group name (group delivery), null when unaddressed */
    public readonly ?string $targetGroup;

    /** @var ?string Accept key to exclude from broadcast, null when nothing is excluded */
    public readonly ?string $excludeAcceptKey;

    /**
     * Creates WebSocket signal data with targeting metadata.
     *
     * An empty target is folded into null here, at the one place every delivery
     * path passes through, so no reader below has to treat the empty string as a
     * second spelling of "not addressed".
     *
     * @param SignalDataInterface $data Inner signal payload
     * @param ?string $targetAcceptKey Target connection accept key (user delivery)
     * @param ?string $targetSessionTokenHash Hash of the target session token (session delivery)
     * @param ?string $targetGroup Target group name (group delivery)
     * @param ?string $excludeAcceptKey Accept key to exclude from broadcast
     */
    public function __construct(
        public readonly SignalDataInterface $data,
        ?string $targetAcceptKey = null,
        ?string $targetSessionTokenHash = null,
        ?string $targetGroup = null,
        ?string $excludeAcceptKey = null,
    ) {
        $this->targetAcceptKey = $targetAcceptKey === '' ? null : $targetAcceptKey;
        $this->targetSessionTokenHash = $targetSessionTokenHash === '' ? null : $targetSessionTokenHash;
        $this->targetGroup = $targetGroup === '' ? null : $targetGroup;
        $this->excludeAcceptKey = $excludeAcceptKey === '' ? null : $excludeAcceptKey;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data with data, dataType, optional target keys
     */
    public function toArray(): array
    {
        $result = SignalDataEnvelope::encode($this->data);

        if ($this->targetAcceptKey !== null) {
            $result['targetAcceptKey'] = $this->targetAcceptKey;
        }

        if ($this->targetSessionTokenHash !== null) {
            $result['targetSessionTokenHash'] = $this->targetSessionTokenHash;
        }

        if ($this->targetGroup !== null) {
            $result['targetGroup'] = $this->targetGroup;
        }

        if ($this->excludeAcceptKey !== null) {
            $result['excludeAcceptKey'] = $this->excludeAcceptKey;
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            data: SignalDataEnvelope::decode(
                $data[SignalPayloadConstants::FIELD_DATA] ?? [],
                $data[SignalPayloadConstants::FIELD_DATA_TYPE] ?? null,
            ),
            targetAcceptKey: $data['targetAcceptKey'] ?? null,
            targetSessionTokenHash: $data['targetSessionTokenHash'] ?? null,
            targetGroup: $data['targetGroup'] ?? null,
            excludeAcceptKey: $data['excludeAcceptKey'] ?? null,
        );
    }
}

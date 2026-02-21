<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalTypeInterface;

/**
 * AgentResponseSignalDTO - DTO for agent response signals
 *
 * Represents a signal sent from an agent to users/clients.
 * Contains signal source (agent), type (delivery type), name, data, and optional targeting.
 */
class AgentResponseSignalDTO extends BaseDTO implements AgentMessageDTOInterface
{
    // Field name constants
    public const string SIGNAL_SOURCE = 'signalSource';
    public const string SIGNAL_TYPE = 'signalType';
    public const string SIGNAL_NAME = 'signalName';
    public const string SIGNAL_DATA = 'signalData';
    public const string TARGET_ACCEPT_KEY = 'targetAcceptKey';
    public const string TARGET_GROUP = 'targetGroup';
    public const string EXCLUDE_ACCEPT_KEY = 'excludeAcceptKey';

    public function __construct(
        public readonly SignalSourceInterface $signalSource,
        public readonly SignalTypeInterface $signalType,
        public readonly SignalNameInterface $signalName,
        public readonly SignalDataInterface $signalData,
        public readonly ?string $targetAcceptKey = null,
        public readonly ?string $targetGroup = null,
        public readonly ?string $excludeAcceptKey = null,
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        $dataArray = $this->signalData instanceof BaseDTO
            ? $this->signalData->toArray()
            : [];

        // Serialize signalSource
        $signalSourceArray = [
            'source' => $this->signalSource->getSource(),
            'type' => $this->signalSource->getType(),
            'index' => $this->signalSource->getIndex(),
        ];

        $result = [
            self::SIGNAL_SOURCE => $signalSourceArray,
            self::SIGNAL_TYPE => $this->signalType->getType(),
            self::SIGNAL_NAME => $this->signalName->getName(),
            self::SIGNAL_DATA => $dataArray,
        ];

        if ($this->targetAcceptKey !== null) {
            $result[self::TARGET_ACCEPT_KEY] = $this->targetAcceptKey;
        }

        if ($this->targetGroup !== null) {
            $result[self::TARGET_GROUP] = $this->targetGroup;
        }

        if ($this->excludeAcceptKey !== null) {
            $result[self::EXCLUDE_ACCEPT_KEY] = $this->excludeAcceptKey;
        }

        return $result;
    }

    /**
     * Create DTO from array
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        // Deserialize signalSource
        $signalSourceData = $data[self::SIGNAL_SOURCE] ?? [];
        if ($signalSourceData instanceof SignalSourceInterface) {
            $signalSource = $signalSourceData;
        } elseif (is_array($signalSourceData)) {
            $signalSource = new SignalSource(
                source: $signalSourceData['source'] ?? '',
                type: $signalSourceData['type'] ?? null,
                index: $signalSourceData['index'] ?? null,
            );
        } else {
            $signalSource = new SignalSource((string)$signalSourceData);
        }

        $signalType = $data[self::SIGNAL_TYPE] instanceof SignalTypeInterface
            ? $data[self::SIGNAL_TYPE]
            : new SignalType($data[self::SIGNAL_TYPE] ?? '');

        $signalName = $data[self::SIGNAL_NAME] instanceof SignalNameInterface
            ? $data[self::SIGNAL_NAME]
            : new SignalName($data[self::SIGNAL_NAME] ?? '');

        $signalData = $data[self::SIGNAL_DATA] ?? [];
        if (!($signalData instanceof SignalDataInterface)) {
            $signalData = new SignalData();
        }

        return new self(
            signalSource: $signalSource,
            signalType: $signalType,
            signalName: $signalName,
            signalData: $signalData,
            targetAcceptKey: $data[self::TARGET_ACCEPT_KEY] ?? null,
            targetGroup: $data[self::TARGET_GROUP] ?? null,
            excludeAcceptKey: $data[self::EXCLUDE_ACCEPT_KEY] ?? null,
        );
    }
}

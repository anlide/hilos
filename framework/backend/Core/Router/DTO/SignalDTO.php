<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Utils\Logger;

/**
 * SignalDTO - DTO for queued signal.
 *
 * Represents a signal queued for dispatch with source, type, name and data.
 */
class SignalDTO extends BaseDTO
{
    /**
     * Creates signal DTO with source, type, name and data.
     *
     * @param SignalSourceInterface $signalSource Signal source (agent, worker, etc.)
     * @param SignalTypeInterface $signalType Signal type (frame, handshake, action, etc.)
     * @param SignalNameInterface $signalName Signal name
     * @param SignalDataInterface $data Signal payload data
     * @param array<string, int|string> $meta Analytics correlation metadata
     */
    public function __construct(
        public readonly SignalSourceInterface $signalSource,
        public readonly SignalTypeInterface $signalType,
        public readonly SignalNameInterface $signalName,
        public readonly SignalDataInterface $data,
        public readonly array $meta = [],
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data with signalSource, signalType, signalName, data, dataType keys
     */
    public function toArray(): array
    {
        // SignalDataInterface guarantees toArray(); store class name for deserialization
        $dataArray = $this->data->toArray();
        $dataType = get_class($this->data);

        // Serialize signalSource - always serialize to array
        $signalSourceArray = [
            'source' => $this->signalSource->getSource(),
            'type' => $this->signalSource->getType(),
            'index' => $this->signalSource->getIndex(),
        ];

        return [
            'signalSource' => $signalSourceArray,
            'signalType' => $this->signalType->getType(),
            'signalName' => $this->signalName->getName(),
            'data' => $dataArray,
            'dataType' => $dataType,
            'meta' => $this->meta,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (signalSource, signalType, signalName, data required)
     * @return static DTO instance
     * @throws InvalidArgumentException If required fields are missing or invalid
     */
    public static function fromArray(array $data): static
    {
        // Validate required fields
        if (!isset($data['signalSource'])) {
            throw new InvalidArgumentException("Missing required field: signalSource");
        }

        if (!isset($data['signalType'])) {
            throw new InvalidArgumentException("Missing required field: signalType");
        }

        if (!isset($data['signalName'])) {
            throw new InvalidArgumentException("Missing required field: signalName");
        }

        // Validate data field type
        if (isset($data['data']) && !is_array($data['data'])) {
            throw new InvalidArgumentException("Field 'data' must be an array, got: " . gettype($data['data']));
        }

        // Validate dataType field type - must be string or null
        if (array_key_exists('dataType', $data) && $data['dataType'] !== null && !is_string($data['dataType'])) {
            throw new InvalidArgumentException("Field 'dataType' must be string or null, got: " . gettype($data['dataType']));
        }

        if (array_key_exists('meta', $data) && !is_array($data['meta'])) {
            throw new InvalidArgumentException("Field 'meta' must be an array, got: " . gettype($data['meta']));
        }

        // Deserialize signalSource
        $signalSourceData = $data['signalSource'];
        if ($signalSourceData instanceof SignalSourceInterface) {
            $signalSource = $signalSourceData;
        } elseif (is_array($signalSourceData)) {
            // From JSON deserialization - array with source, type, index
            $signalSource = new SignalSource(
                source: $signalSourceData['source'] ?? '',
                type: $signalSourceData['type'] ?? null,
                index: $signalSourceData['index'] ?? null,
            );
        } else {
            // Fallback: treat as string
            $signalSource = new SignalSource((string)$signalSourceData);
        }

        // Deserialize signalType
        if ($data['signalType'] instanceof SignalTypeInterface) {
            $signalType = $data['signalType'];
        } else {
            $signalType = new SignalType($data['signalType'] ?? '');
        }

        // Deserialize signalName
        if ($data['signalName'] instanceof SignalNameInterface) {
            $signalName = $data['signalName'];
        } else {
            $signalName = new SignalName($data['signalName'] ?? '');
        }

        // Deserialize signalData
        $dataArray = $data['data'] ?? [];
        $dataType = $data['dataType'] ?? null;
        $signalData = self::deserializeSignalData($dataArray, $dataType);

        return new static(
            signalSource: $signalSource,
            signalType: $signalType,
            signalName: $signalName,
            data: $signalData,
            meta: $data['meta'] ?? [],
        );
    }

    /**
     * Deserializes signal data from array.
     *
     * @param array<string, mixed> $dataArray Signal data array
     * @param ?class-string $dataType Signal data class name for deserialization
     * @return SignalDataInterface Deserialized signal data
     */
    private static function deserializeSignalData(array $dataArray, ?string $dataType): SignalDataInterface
    {
        // If dataType is provided, try to deserialize using that class
        if (is_string($dataType) && $dataType !== '') {
            // Aggressively try to load the class - it should be available via autoload
            // Try multiple times with different approaches
            $classLoaded = false;

            // Try to load the class
            if (!class_exists($dataType, false)) {
                spl_autoload_call($dataType);
            }

            if (class_exists($dataType, true)) {
                // Check if class implements SignalDataInterface
                if (is_a($dataType, SignalDataInterface::class, true)) {
                    try {
                        $result = $dataType::fromArray($dataArray);
                        Logger::debug("Deserialized signal data as {$dataType}");
                        return $result;
                    } catch (\Throwable $e) {
                        // If deserialization fails, try framework fallback
                        Logger::error("Failed to deserialize signal data as {$dataType}: {$e->getMessage()}");

                        // Try framework class fallback
                        $fallbackResult = self::tryFrameworkClassFallback($dataType, $dataArray);
                        if ($fallbackResult !== null) {
                            return $fallbackResult;
                        }
                    }
                } else {
                    Logger::error("Signal data type {$dataType} does not implement SignalDataInterface");
                }
            } else {
                // Class not found - try framework fallback
                Logger::debug("Class {$dataType} not found, attempting framework fallback");

                // Try framework class fallback
                $fallbackResult = self::tryFrameworkClassFallback($dataType, $dataArray);
                if ($fallbackResult !== null) {
                    return $fallbackResult;
                }
            }
        }

        // Final fallback: create SignalData with provided data array
        Logger::debug("Using SignalData fallback");
        return SignalData::fromArray($dataArray);
    }

    /**
     * Try to deserialize using framework class fallback
     *
     * Attempts to find and use framework equivalent of custom class.
     *
     * @param class-string $originalClass Original class name (may be custom class outside framework)
     * @param array<string, mixed> $dataArray Data array for deserialization
     * @return ?SignalDataInterface Deserialized signal data or null if fallback failed
     */
    private static function tryFrameworkClassFallback(string $originalClass, array $dataArray): ?SignalDataInterface
    {
        $frameworkClass = self::getFrameworkClassFallback($originalClass);
        if ($frameworkClass === null) {
            return null;
        }

        // Check if framework class exists (with autoload enabled)
        if (!class_exists($frameworkClass, true)) {
            Logger::debug("Framework fallback class {$frameworkClass} not found");
            return null;
        }

        try {
            Logger::debug("Using framework fallback: {$frameworkClass} (original: {$originalClass})");
            $result = $frameworkClass::fromArray($dataArray);

            // Try to normalize back to original class if it's now available
            if (!class_exists($originalClass, false)) {
                spl_autoload_call($originalClass);
            }

            if (class_exists($originalClass, true) && is_a($originalClass, SignalDataInterface::class, true)) {
                try {
                    $normalizedResult = $originalClass::fromArray($dataArray);
                    Logger::debug("Normalized to original class: {$originalClass}");
                    return $normalizedResult;
                } catch (\Throwable $e) {
                    Logger::debug("Normalization failed, using framework class: {$e->getMessage()}");
                }
            }

            return $result;
        } catch (\Throwable $e) {
            Logger::error("Framework class fallback failed for {$frameworkClass}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get framework class fallback for custom class
     *
     * Intelligently maps custom classes (outside Hilos namespace) to their framework equivalents
     * by analyzing namespace structure.
     *
     * Examples:
     * - Custom\Chat\DTO\WebSocketHandshakeSignalDTO -> Hilos\DTO\WebSocket\WebSocketHandshakeSignalDTO
     * - Custom\SomeApp\DTO\Agent\AgentSignalDTO -> Hilos\DTO\Agent\AgentSignalDTO
     * - Custom\SomeApp\DTO\SomeDTO -> Hilos\DTO\SomeDTO
     *
     * @param class-string $customClass Custom class name (outside Hilos namespace)
     * @return ?class-string Framework class name or null if no mapping exists
     */
    private static function getFrameworkClassFallback(string $customClass): ?string
    {
        // Check if it's a custom class (not in Hilos namespace)
        if (str_starts_with($customClass, 'Hilos\\')) {
            return null;
        }

        // Parse namespace structure: Custom\Chat\DTO\WebSocketHandshakeSignalDTO
        $parts = explode('\\', $customClass);
        $className = end($parts); // Last part is class name

        // Build candidate list based on namespace structure
        $candidates = [];

        // If namespace has more than 4 parts (Custom\App\DTO\SubNamespace\ClassName),
        // try to preserve middle parts in framework namespace
        // Example: Custom\SomeApp\DTO\Agent\SomeDTO -> Hilos\DTO\Agent\SomeDTO
        if (count($parts) > 4) {
            // Extract middle parts between Custom\App\DTO and ClassName
            // Custom\SomeApp\DTO\Agent\SomeDTO -> [Agent]
            $middleParts = array_slice($parts, 3, -1);
            if (!empty($middleParts)) {
                $middlePath = implode('\\', $middleParts);
                $candidates[] = "Hilos\\DTO\\{$middlePath}\\{$className}";
            }
        }

        // Always try WebSocket namespace first (common case)
        $candidates[] = "Hilos\\DTO\\WebSocket\\{$className}";

        // Try generic DTO namespace as fallback
        $candidates[] = "Hilos\\DTO\\{$className}";

        // Try each candidate and return first that exists
        return array_find($candidates, fn($candidate) => class_exists($candidate, true));

        // No framework class found
    }
}

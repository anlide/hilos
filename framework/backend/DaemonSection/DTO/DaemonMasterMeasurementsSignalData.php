<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Master's last completed measurement sent to its own node agent. */
final class DaemonMasterMeasurementsSignalData extends BaseDTO implements SignalDataInterface
{
    public const string nodeId = 'nodeId';

    /**
     * @param string $nodeId Non-empty source node id
     * @param DaemonMeasurements $measurements Last completed measurement
     * @throws InvalidFormatException When the node id is empty
     */
    public function __construct(public readonly string $nodeId, public readonly DaemonMeasurements $measurements)
    {
        if ($nodeId === '') {
            throw new InvalidFormatException('Daemon master measurements carry an empty node id');
        }
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [self::nodeId => $this->nodeId] + DaemonStatusDTO::measurementsToArray($this->measurements);
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Parsed master measurement
     * @throws InvalidFormatException When a required field or value relationship is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::nodeId),
            DaemonStatusDTO::measurementsFromArray($data),
        );
    }
}

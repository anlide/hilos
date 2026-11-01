<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Test-only ask from a master to the Daemon agent on the same node: report now and answer the held command. */
final class DaemonMasterReportNowSignalData extends BaseDTO implements SignalDataInterface
{
    public const string nodeId = 'nodeId';
    public const string correlationId = 'correlationId';

    /**
     * @param string $nodeId Non-empty source node id
     * @param string $correlationId Non-empty correlation of the held console request
     * @throws InvalidFormatException When the node id or the correlation is empty
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $correlationId,
    ) {
        if ($nodeId === '' || $correlationId === '') {
            throw new InvalidFormatException('Daemon report-now ask carries an empty node id or correlation');
        }
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::correlationId => $this->correlationId,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Parsed report-now ask
     * @throws InvalidFormatException When a required field is absent, mistyped or empty
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::nodeId),
            self::requireString($data, self::correlationId),
        );
    }
}

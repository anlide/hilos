<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DaemonSection\NodeDaemonPicture;

/** Complete node picture sent from the node agent to the collector. */
final class DaemonNodePictureSignalData extends BaseDTO implements SignalDataInterface
{
    public const string nodeId = 'nodeId';
    public const string role = 'role';
    public const string sampledAt = 'sampledAt';
    public const string processes = 'processes';

    public function __construct(public readonly NodeDaemonPicture $picture)
    {
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->picture->nodeId,
            self::role => $this->picture->role->value,
            self::sampledAt => $this->picture->sampledAt,
            self::processes => $this->picture->processes === null
                ? null
                : DaemonMasterProcessRosterSignalData::rosterToArray($this->picture->processes),
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Parsed picture
     * @throws InvalidFormatException When a field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        $nodeId = self::requireString($data, self::nodeId);
        $role = NodeRole::tryFrom(self::requireString($data, self::role));
        if ($nodeId === '' || $role === null) {
            throw new InvalidFormatException('Daemon node picture carries an empty node id or invalid role');
        }

        $processes = self::requireNullableArray($data, self::processes);

        return new static(new NodeDaemonPicture(
            $nodeId,
            $role,
            self::requireInt($data, self::sampledAt),
            $processes === null ? null : DaemonMasterProcessRosterSignalData::rosterFromArray($processes),
        ));
    }
}

<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DaemonSection\DaemonClockPicture;
use Hilos\DaemonSection\DaemonClockReading;
use Hilos\DaemonSection\DaemonConsensusPicture;
use Hilos\DaemonSection\DaemonNodeStanding;

/** Whole standing section sent by the master to its own node agent. */
final class DaemonMasterStandingSignalData extends BaseDTO implements SignalDataInterface
{
    public const string nodeId = 'nodeId';
    public const string sessions = 'sessions';
    public const string connections = 'connections';
    public const string clustered = 'clustered';
    public const string consensus = 'consensus';
    public const string commandPort = 'commandPort';
    public const string clock = 'clock';
    public const string thresholdMs = 'thresholdMs';
    public const string nodes = 'nodes';
    public const string offsetMs = 'offsetMs';
    public const string measuredAt = 'measuredAt';
    public const string role = 'role';
    public const string term = 'term';
    public const string leaderId = 'leaderId';
    public const string quorum = 'quorum';
    public const string online = 'online';
    public const string masters = 'masters';
    public const string needed = 'needed';

    /**
     * @param string $nodeId Non-empty source node id
     * @param DaemonNodeStanding $standing Whole standing section
     * @throws InvalidFormatException When the node id is empty
     */
    public function __construct(public readonly string $nodeId, public readonly DaemonNodeStanding $standing)
    {
        if ($nodeId === '') {
            throw new InvalidFormatException('Daemon master standing carries an empty node id');
        }
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [self::nodeId => $this->nodeId] + self::standingToArray($this->standing);
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Parsed master standing
     * @throws InvalidFormatException When a field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::nodeId), self::standingFromArray($data));
    }

    /**
     * @param DaemonNodeStanding $standing Standing section of a whole node picture
     * @return array<string, mixed> Shared wire shape without node id
     */
    public static function standingToArray(DaemonNodeStanding $standing): array
    {
        $consensus = $standing->consensus;
        return [
            self::sessions => $standing->sessions,
            self::connections => $standing->connections,
            self::clustered => $standing->clustered,
            self::commandPort => $standing->commandPort,
            self::consensus => $consensus === null ? null : [
                self::role => $consensus->role->value,
                self::term => $consensus->term,
                self::leaderId => $consensus->leaderId,
                self::quorum => [
                    self::online => $consensus->onlineMasters,
                    self::masters => $consensus->masters,
                    self::needed => $consensus->quorumSize,
                ],
            ],
            self::clock => $standing->clock === null ? null : [
                self::thresholdMs => $standing->clock->thresholdMs,
                self::nodes => array_map(static fn (DaemonClockReading $reading): array => [
                    self::nodeId => $reading->nodeId,
                    self::offsetMs => $reading->offsetMs,
                    self::measuredAt => $reading->measuredAt,
                ], $standing->clock->nodes),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data Shared wire shape without node id
     * @return DaemonNodeStanding Parsed standing section
     * @throws InvalidFormatException When a field is absent or invalid
     */
    public static function standingFromArray(array $data): DaemonNodeStanding
    {
        $consensusData = self::requireNullableArray($data, self::consensus);
        $clockData = self::requireNullableArray($data, self::clock);
        $consensus = null;
        if ($consensusData !== null) {
            $role = ConsensusRole::tryFrom(self::requireString($consensusData, self::role));
            if ($role === null) {
                throw new InvalidFormatException('Daemon consensus picture carries an invalid role');
            }
            $quorum = self::requireArray($consensusData, self::quorum);
            $consensus = new DaemonConsensusPicture(
                $role,
                self::requireInt($consensusData, self::term),
                self::requiredNullableString($consensusData, self::leaderId),
                self::requireInt($quorum, self::online),
                self::requireInt($quorum, self::masters),
                self::requireInt($quorum, self::needed),
            );
        }

        return new DaemonNodeStanding(
            self::requireInt($data, self::sessions),
            self::requireInt($data, self::connections),
            self::requireBool($data, self::clustered),
            $consensus,
            self::requiredNullableInt($data, self::commandPort),
            $clockData === null ? null : self::clockFromArray($clockData),
        );
    }

    /**
     * @param array<string, mixed> $data Clock wire object
     * @return DaemonClockPicture Validated leader clock picture
     * @throws InvalidFormatException When a clock field or reading is absent or invalid
     */
    private static function clockFromArray(array $data): DaemonClockPicture
    {
        $rows = self::requireArray($data, self::nodes);
        if (!array_is_list($rows)) {
            throw new InvalidFormatException('Daemon clock picture nodes must be a list');
        }
        $nodes = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidFormatException('Daemon clock reading must be an object');
            }
            $nodes[] = new DaemonClockReading(
                self::requireString($row, self::nodeId),
                self::requireInt($row, self::offsetMs),
                self::requireInt($row, self::measuredAt),
            );
        }
        return new DaemonClockPicture(self::requireInt($data, self::thresholdMs), $nodes);
    }

    /**
     * @param array<string, mixed> $data Wire object
     * @param string $key Required nullable integer key
     * @return ?int Integer or explicit null
     * @throws InvalidFormatException When the field is absent or invalid
     */
    private static function requiredNullableInt(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidFormatException('Payload carries no integer or null under key ' . $key);
        }
        return self::optionalInt($data, $key);
    }

    /**
     * @param array<string, mixed> $data Wire object
     * @param string $key Required nullable string key
     * @return ?string String or explicit null
     * @throws InvalidFormatException When the field is absent or invalid
     */
    private static function requiredNullableString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidFormatException('Payload carries no string or null under key ' . $key);
        }
        return self::optionalString($data, $key);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
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
        );
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

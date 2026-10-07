<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;

/** Full snapshot or changed whole node views from collector to page agent. */
final class DaemonClusterPicturePortionSignalData extends BaseDTO implements SignalDataInterface
{
    public const string snapshot = 'snapshot';
    public const string nodes = 'nodes';
    public const string nodeId = 'nodeId';
    public const string online = 'online';
    public const string receivedAt = 'receivedAt';
    public const string picture = 'picture';

    /** @param list<ClusterDaemonNodeView> $nodes */
    public function __construct(public readonly bool $snapshot, public readonly array $nodes)
    {
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [
            self::snapshot => $this->snapshot,
            self::nodes => array_map(
                static fn (ClusterDaemonNodeView $node): array => [
                    self::nodeId => $node->nodeId,
                    self::online => $node->online,
                    self::receivedAt => $node->slot?->receivedAt,
                    self::picture => $node->slot === null ? null : (new DaemonNodePictureSignalData($node->slot->picture))->toArray(),
                ],
                $this->nodes,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Parsed portion
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        $rows = self::requireArray($data, self::nodes);
        if (!array_is_list($rows)) {
            throw new InvalidFormatException('Daemon cluster portion nodes must be a list');
        }
        $nodes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('Daemon cluster portion carries a node that is not an object');
            }
            $nodeId = self::requireString($row, self::nodeId);
            $online = self::requireBool($row, self::online);
            if ($nodeId === '' || !array_key_exists(self::receivedAt, $row) || !array_key_exists(self::picture, $row)) {
                throw new InvalidFormatException('Daemon cluster portion carries an incomplete node');
            }
            $receivedAt = $row[self::receivedAt];
            $pictureData = $row[self::picture];
            if (($receivedAt === null) !== ($pictureData === null)) {
                throw new InvalidFormatException('Daemon cluster portion carries a half-known node picture');
            }
            $slot = null;
            if ($pictureData !== null) {
                if (!is_int($receivedAt) || !is_array($pictureData)) {
                    throw new InvalidFormatException('Daemon cluster portion carries an invalid node picture');
                }
                $picture = DaemonNodePictureSignalData::fromArray($pictureData)->picture;
                if ($picture->nodeId !== $nodeId) {
                    throw new InvalidFormatException('Daemon cluster portion node ids disagree');
                }
                $slot = new ClusterDaemonNodeSlot($nodeId, $picture, $receivedAt);
            }
            $nodes[] = new ClusterDaemonNodeView($nodeId, $online, $slot);
        }

        return new static(self::requireBool($data, self::snapshot), $nodes);
    }
}

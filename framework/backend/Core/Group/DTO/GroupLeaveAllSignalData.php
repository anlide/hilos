<?php

declare(strict_types=1);

namespace Hilos\Core\Group\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Group\GroupMembership;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\Worker\DTO\WorkerGroupLeaveAllDTO;

/**
 * GroupLeaveAllSignalData - connections whose person changed, on their way out of every group (HIL-1284).
 *
 * Queued by {@see GroupMembership::leaveAll()} and drained by the worker into a
 * {@see WorkerGroupLeaveAllDTO}; the master queues it again, naming the sender, to fan the
 * same frame out to the other workers of its node. It names connections and no group: the
 * rule ends every membership the connection held, and the group a membership was written
 * under is not the sender's to know - another worker, or another node, may have admitted it.
 */
final class GroupLeaveAllSignalData extends BaseDTO implements SignalDataInterface
{
    public const string acceptKeys = 'acceptKeys';
    public const string exceptWorkerIndex = 'exceptWorkerIndex';

    /**
     * Creates a leave-all announcement.
     *
     * @param list<string> $acceptKeys Connections leaving every group; never empty
     * @param ?int $exceptWorkerIndex Worker the master does not forward the frame to - the one that
     *     sent it, which has already cleared its mirror and may have joined again since; null from
     *     the sending worker and on the frame the master hands its workers
     */
    public function __construct(
        public readonly array $acceptKeys,
        public readonly ?int $exceptWorkerIndex = null,
    ) {
    }

    /**
     * Converts the announcement to its array payload.
     *
     * @return array<string, mixed> DTO payload in the `{acceptKeys, exceptWorkerIndex}` form
     */
    public function toArray(): array
    {
        return [
            self::acceptKeys => $this->acceptKeys,
            self::exceptWorkerIndex => $this->exceptWorkerIndex,
        ];
    }

    /**
     * Restores the announcement from its array payload.
     *
     * An entry that is not a non-empty string refuses the whole frame rather than dropping out
     * of it: a connection read out of the list would keep the groups of the person who left.
     *
     * @param array<string, mixed> $data Source data
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the key list is missing, empty, or holds anything but
     *     accept keys, or the worker index is not an integer
     */
    public static function fromArray(array $data): static
    {
        $acceptKeys = self::requireArray($data, self::acceptKeys);
        if ($acceptKeys === [] || !array_is_list($acceptKeys)) {
            throw new InvalidFormatException('Group leave-all payload carries no list of accept keys');
        }
        foreach ($acceptKeys as $acceptKey) {
            if (!is_string($acceptKey) || $acceptKey === '') {
                throw new InvalidFormatException('Group leave-all payload carries an entry that is not an accept key');
            }
        }

        return new static(
            acceptKeys: $acceptKeys,
            exceptWorkerIndex: self::optionalInt($data, self::exceptWorkerIndex),
        );
    }
}

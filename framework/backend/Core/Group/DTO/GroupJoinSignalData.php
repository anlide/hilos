<?php

declare(strict_types=1);

namespace Hilos\Core\Group\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\Worker\DTO\WorkerGroupJoinDTO;

/**
 * GroupJoinSignalData - the membership a worker admitted, on its way to the master.
 *
 * Queued by {@see AbstractGroup::onSubscribe()} once admission has passed, drained by the
 * worker into a {@see WorkerGroupJoinDTO}. It carries the FULL group name, which is the
 * whole reason the frame exists: the master forwarded a client frame naming something else
 * (or nothing at all, for a group the server addresses itself), and it may not read a
 * session to work out the difference.
 */
final class GroupJoinSignalData extends BaseDTO implements SignalDataInterface
{
    public const string group = 'group';
    public const string acceptKey = 'acceptKey';
    public const string params = 'params';

    /**
     * Creates a group join announcement.
     *
     * @param string $group Full group name the connection was let into
     * @param string $acceptKey WebSocket accept key of the joined connection
     * @param array<string, string> $params Subscription params carried by the join frame
     */
    public function __construct(
        public readonly string $group,
        public readonly string $acceptKey,
        public readonly array $params = [],
    ) {
    }

    /**
     * Converts the announcement to its array payload.
     *
     * @return array<string, mixed> DTO payload in the `{group, acceptKey, params}` form
     */
    public function toArray(): array
    {
        return [
            self::group => $this->group,
            self::acceptKey => $this->acceptKey,
            self::params => $this->params,
        ];
    }

    /**
     * Restores the announcement from its array payload.
     *
     * @param array<string, mixed> $data Source data
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the group name or the accept key is missing
     */
    public static function fromArray(array $data): static
    {
        return new static(
            group: self::requireString($data, self::group),
            acceptKey: self::requireString($data, self::acceptKey),
            params: self::optionalArray($data, self::params) ?? [],
        );
    }
}

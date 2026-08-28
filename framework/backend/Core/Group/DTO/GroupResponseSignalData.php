<?php

declare(strict_types=1);

namespace Hilos\Core\Group\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;

/**
 * GroupResponseSignalData - server-to-client answer to a group join.
 *
 * Carries the `{group, payload?}` wire form sent to the connection that joined: the group
 * name is the FULL one, the address the connection was actually let into, because for a
 * group the server addresses itself the name the client sent and the name it joined are
 * different, and the client opens its group scope under the latter. The payload is whatever
 * the group class built ({@see AbstractGroup::buildGroupPayload()}), and a group that
 * carries no content is a legitimate outcome rather than an error.
 */
final class GroupResponseSignalData extends BaseDTO implements SignalDataInterface
{
    public const string group = 'group';
    public const string payload = 'payload';

    /**
     * Creates a group response signal payload.
     *
     * @param string $group Full group name the connection joined
     * @param ?SignalDataInterface $payload Content the group built, or null when it carries none
     */
    public function __construct(
        public readonly string $group,
        public readonly ?SignalDataInterface $payload = null,
    ) {
    }

    /**
     * Converts the signal payload to its wire array.
     *
     * A group with no content omits the key rather than sending an empty map, for the reason
     * {@see PageResponseSignalData::toArray()} omits its own: PHP encodes an empty array as
     * the JSON array `[]`, which an object-shaped payload schema rejects, and the frame - the
     * acknowledgement a joining connection waits on - would die at the parse boundary.
     *
     * @return array<string, mixed> DTO payload in the `{group, payload?}` wire form
     */
    public function toArray(): array
    {
        $wire = [self::group => $this->group];
        $payload = $this->payload?->toArray() ?? [];
        if ($payload !== []) {
            $wire[self::payload] = $payload;
        }

        return $wire;
    }

    /**
     * Restores the signal payload from its wire array.
     *
     * The content comes back as the untyped {@see SignalData} rather than the class that
     * built it: the frame is common to every group, so it names no payload type and there is
     * nothing here to rebuild one from. The wire shape survives the roundtrip, which is what
     * this side is for.
     *
     * @param array<string, mixed> $data Source data in the `{group, payload?}` wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the group name is missing, or the payload is not a map
     */
    public static function fromArray(array $data): static
    {
        $payload = self::optionalArray($data, self::payload);

        return new static(
            group: self::requireString($data, self::group),
            payload: $payload === null ? null : new SignalData($payload),
        );
    }
}

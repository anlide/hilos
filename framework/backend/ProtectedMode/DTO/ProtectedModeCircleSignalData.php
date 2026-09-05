<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\VerifierCircleSnapshot;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModeCircleSignalData - initiator -> daemon payload for PROTECTED_MODE_CIRCLE.
 *
 * The verifier circle as the freezing node photographed it (HIL-643), on its way to the row that
 * decides who the verification window lets in. It travels for the reason the pass does: the
 * photograph is three database queries and the master is forbidden the database, while the row it
 * lands on is the master's to write ({@see ProtectedModeRuntime::$circleSessionTokenHashes}). So
 * the worker reads and the master writes, and this frame is the seam between them.
 *
 * Both numbers ride together because they are read together: the hashes say who gets in, and the
 * named count is what tells "nobody was named" from "nobody named was online" afterwards, when the
 * circle table has already been replaced by the archive's own ({@see VerifierCircleSnapshot}).
 *
 * Unlike the pass this one is a whole list rather than one more entry, and it is authorized by the
 * same recorded agent identity every other request on this channel is.
 */
final class ProtectedModeCircleSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the agent type that photographed the circle. */
    public const string initiatorAgentType = 'initiatorAgentType';

    /** Payload key: the agent index that photographed the circle. */
    public const string initiatorAgentIndex = 'initiatorAgentIndex';

    /** Payload key: how many people the circle named at the freeze. */
    public const string namedCount = 'namedCount';

    /** Payload key: session token hashes of the named people who were online. */
    public const string sessionTokenHashes = 'sessionTokenHashes';

    /**
     * @param string $initiatorAgentType Agent type that photographed the circle
     * @param ?int $initiatorAgentIndex Agent index, or null for a singleton agent
     * @param int $namedCount How many people the circle named at the freeze
     * @param list<string> $sessionTokenHashes Session token hashes of the named people who were online
     */
    public function __construct(
        public readonly string $initiatorAgentType,
        public readonly ?int $initiatorAgentIndex,
        public readonly int $namedCount,
        public readonly array $sessionTokenHashes,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::initiatorAgentType => $this->initiatorAgentType,
            self::initiatorAgentIndex => $this->initiatorAgentIndex,
            self::namedCount => $this->namedCount,
            self::sessionTokenHashes => $this->sessionTokenHashes,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no agent, no count or no hash list
     */
    public static function fromArray(array $data): static
    {
        $agentIndex = $data[self::initiatorAgentIndex] ?? null;

        $sessionTokenHashes = [];
        foreach (self::requireArray($data, self::sessionTokenHashes) as $sessionTokenHash) {
            if (is_string($sessionTokenHash) && $sessionTokenHash !== '') {
                $sessionTokenHashes[] = $sessionTokenHash;
            }
        }

        return new static(
            initiatorAgentType: self::requireString($data, self::initiatorAgentType),
            initiatorAgentIndex: $agentIndex === null ? null : (int)$agentIndex,
            namedCount: self::requireInt($data, self::namedCount),
            sessionTokenHashes: $sessionTokenHashes,
        );
    }
}

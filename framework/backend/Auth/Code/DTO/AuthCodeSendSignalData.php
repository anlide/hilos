<?php

declare(strict_types=1);

namespace Hilos\Auth\Code\DTO;

use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * AuthCodeSendSignalData - the page action's handoff to the code agent (HIL-492).
 *
 * Everything {@see AuthCodeAgent} needs to probe, mint and deliver one code, and
 * nothing more. It crosses a process boundary (the page runs on a worker, the agent
 * is a monopolistic singleton elsewhere), so it carries values and no objects: the
 * channel is named, not passed, and the agent resolves it from the registry it owns.
 *
 * {@see acceptKey} is the return address of the whole operation. The person asking
 * is a guest - no user id, no session to fan out on - so their live socket is the
 * only thing that can be answered, and it is recorded here at the moment of the ask.
 * A connection that is gone by the time the outcome is ready simply has nowhere to
 * receive it, which is the correct end for a browser that left.
 *
 * {@see sessionToken} is the OTHER address, and it outlives the first (HIL-486): a
 * code that really goes out is remembered against the session, so a browser that
 * reloads - or a second tab, or a second device - is given its code screen back at
 * the handshake. The session is carried rather than looked up from the accept key,
 * because the moment the memory is written is minutes after the ask and the socket
 * that asked may already be gone - which is precisely the case the memory exists for.
 *
 * No code and no secret ever rides this payload: it is the REQUEST for a code, minted
 * later and inside the agent.
 */
final class AuthCodeSendSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $acceptKey Requesting connection's accept key, the address the result goes back to
     * @param string $sessionToken Requesting connection's session token, the address a sent code is remembered against
     * @param string $identifier Normalized identifier the code goes to (E.164 phone)
     * @param string $channel Code channel name the person chose (see CodeChannel::name())
     * @param string $type Verification type the code is minted for (see VerificationType)
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $sessionToken,
        public readonly string $identifier,
        public readonly string $channel,
        public readonly string $type,
    ) {
    }

    /**
     * @return array<string, string> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'sessionToken' => $this->sessionToken,
            'identifier' => $this->identifier,
            'channel' => $this->channel,
            'type' => $this->type,
        ];
    }

    /**
     * Rebuilds the request the page action handed off.
     *
     * Every field is required: a request missing any one of them names no target, no
     * channel or nobody to answer, and guessing a default would send a real message.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a field the handoff needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, 'acceptKey'),
            sessionToken: self::requireString($data, 'sessionToken'),
            identifier: self::requireString($data, 'identifier'),
            channel: self::requireString($data, 'channel'),
            type: self::requireString($data, 'type'),
        );
    }
}

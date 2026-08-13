<?php

declare(strict_types=1);

namespace Hilos\Auth\Flow\DTO;

use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;

/**
 * AuthConvergeSignalData - a step change nobody in this browser asked for (HIL-415).
 *
 * The push half of the auth surface's converge property. {@see AuthFlowOutcome}
 * answers the session that submitted; this says the same thing to the sessions that
 * did not - the ones parked on the code step of an identifier somebody else is
 * confirming, or of a hold that just expired. Delivered WS_USER to one waiting
 * connection's accept key, one signal per waiter.
 *
 * It carries the identifier it is about because a connection can only have been
 * parked on one address, and a step change for a different one must be ignorable
 * rather than applied to whatever the person is typing now.
 *
 * Two cases produce it, and the difference is entirely in the fields: a confirmed
 * registration sends {@see AuthFlowStep::DONE} with no code (the session is signed
 * in as the new account by then), and an expired reservation sends
 * {@see AuthFlowStep::IDENTIFIER} under {@see AuthFlowIntent::REGISTER} with
 * {@see AuthFlowOutcome::CODE_RESERVATION_EXPIRED}, so the surface rolls the person
 * back to the address field instead of rejecting the code they were about to type
 * with an error that would read as "wrong code".
 */
final class AuthConvergeSignalData extends BaseDTO implements SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    /** Wire key for the target accept key. */
    private const string FIELD_ACCEPT_KEY = 'acceptKey';

    /** Wire key for the identifier this converge is about. */
    private const string FIELD_IDENTIFIER = 'identifier';

    /** Wire key for the step the surface moves to. */
    private const string FIELD_STEP = 'step';

    /** Wire key for the intent the surface moves under. */
    private const string FIELD_INTENT = 'intent';

    /** Wire key for the semantic reason of a rollback. */
    private const string FIELD_CODE = 'code';

    /**
     * @param string $acceptKey Waiting connection accept key the signal targets
     * @param string $identifier Identifier the parked session was waiting on
     * @param string $step Step the surface moves to (see AuthFlowStep)
     * @param string $intent Intent the surface moves under (see AuthFlowIntent)
     * @param ?string $code Semantic reason when the move is a rollback, or null
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $identifier,
        public readonly string $step,
        public readonly string $intent,
        public readonly ?string $code = null,
    ) {
    }

    /**
     * @return string Accept key the converge signal is delivered to
     */
    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * @return array<string, string> DTO payload for transport; the reason is omitted when absent
     */
    public function toArray(): array
    {
        $data = [
            self::FIELD_ACCEPT_KEY => $this->acceptKey,
            self::FIELD_IDENTIFIER => $this->identifier,
            self::FIELD_STEP => $this->step,
            self::FIELD_INTENT => $this->intent,
        ];
        if ($this->code !== null) {
            $data[self::FIELD_CODE] = $this->code;
        }

        return $data;
    }

    /**
     * The four required fields are required in the literal sense: a converge with no
     * target reaches nobody, and one with no step tells the surface to do nothing. A
     * frame missing any of them is refused rather than turned into a stub that travels.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a required field is missing or of another type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, self::FIELD_ACCEPT_KEY),
            identifier: self::requireString($data, self::FIELD_IDENTIFIER),
            step: self::requireString($data, self::FIELD_STEP),
            intent: self::requireString($data, self::FIELD_INTENT),
            code: self::optionalString($data, self::FIELD_CODE),
        );
    }
}

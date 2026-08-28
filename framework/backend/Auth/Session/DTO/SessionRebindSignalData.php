<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Project agent → sessions library: make this session say this instead.
 *
 * The way back over the seam of HIL-710, and the only write a project may ask for on a
 * session it no longer owns: sign this session in as that person, sign it out, put it
 * behind an impersonating administrator or take it back out. The guards that decide
 * whether it may - an administrator acting, a target that exists, no nesting - are the
 * project's and run before this is sent, because they read project fields.
 *
 * It carries the TARGET state whole rather than the change to apply. A delta would need a
 * third meaning beside "this user" and "nobody" - "leave it as it was" - and the caller
 * always knows the whole answer anyway: it has just read the row. So the library derives
 * the operation from the state instead of being told it: a null user is a sign-out, any
 * other is a bind, and the impersonation marker is written before either, exactly where
 * the single-process version wrote it.
 *
 * The correlation id is what makes an operator's command answerable across the seam
 * (HIL-622 order, HIL-710 split): the project can no longer see the outcome of a rebind it
 * asked for, so the library replies to the parked command socket itself, with what
 * actually happened rather than with "accepted".
 */
final class SessionRebindSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $sessionToken Session cookie token to rebind
     * @param ?int $userId User the session must be bound to, or null to revert it to anonymous
     * @param ?int $impersonatorUserId Administrator behind the takeover, or null when there is none
     * @param ?string $initiatorAcceptKey Accept key of the connection that asked, or null when no connection did
     * @param ?string $correlationId Command correlation id to answer the operator on, or null when nobody waits
     */
    public function __construct(
        public readonly string $sessionToken,
        public readonly ?int $userId,
        public readonly ?int $impersonatorUserId = null,
        public readonly ?string $initiatorAcceptKey = null,
        public readonly ?string $correlationId = null,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'sessionToken' => $this->sessionToken,
            'userId' => $this->userId,
            'impersonatorUserId' => $this->impersonatorUserId,
            'initiatorAcceptKey' => $this->initiatorAcceptKey,
            'correlationId' => $this->correlationId,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no session
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sessionToken: self::requireString($data, 'sessionToken'),
            userId: self::optionalInt($data, 'userId'),
            impersonatorUserId: self::optionalInt($data, 'impersonatorUserId'),
            initiatorAcceptKey: self::optionalString($data, 'initiatorAcceptKey'),
            correlationId: self::optionalString($data, 'correlationId'),
        );
    }
}

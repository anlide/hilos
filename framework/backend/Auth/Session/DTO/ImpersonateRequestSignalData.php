<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Pages\Users\AbstractHilosUsersPage;

/**
 * Hilos users page → sessions library: take this person over (HIL-824).
 *
 * What {@see HilosSignalConstants::HILOS_IMPERSONATE_REQUEST} carries. The admin surface
 * keeps the action and the ADMIN level closing it ({@see AbstractHilosUsersPage}), because an
 * agent action has no such level to inherit; the session being rebound is owned by
 * {@see AbstractSessionsLibraryAgent}, so the takeover is judged and written there.
 *
 * The target is named by id and by nothing else - who is ASKING is never on the payload. It is
 * read off the connection that submitted, which the accept key names, exactly as it was while
 * the action stood on the library.
 *
 * Everything but the target is the admin waiting, not part of the takeover
 * ({@see HandoverAskInterface}): whom to answer and on which request, the action the ack is
 * addressed to, and the name the answer travels under. No sentence rides with it - the takeover
 * arrives as the rebound session on the handshake the library publishes.
 */
final class ImpersonateRequestSignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param int $targetUserId User id the admin session asks to act as
     * @param string $replySignal Agent-signal name the library reports back under
     * @param string $acceptKey Initiating connection accept key, both the asker and whom to answer
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     */
    public function __construct(
        public readonly int $targetUserId,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
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
            'targetUserId' => $this->targetUserId,
            'replySignal' => $this->replySignal,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'successMessage' => $this->successMessage,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no user to impersonate, no connection or no action
     */
    public static function fromArray(array $data): static
    {
        return new static(
            targetUserId: self::requireInt($data, 'targetUserId'),
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
        );
    }
}

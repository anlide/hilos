<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Hilos user sessions page → sessions library: end one or every session of this person (HIL-1662).
 *
 * What {@see HilosSignalConstants::HILOS_USER_SESSIONS_END_REQUEST} carries. The page keeps the
 * browser actions and the ADMIN level closing them; the sessions are owned by
 * {@see AbstractSessionsLibraryAgent}, so the ending is judged and written there, and the library
 * answers under {@see HilosSignalConstants::HILOS_USER_SESSIONS_END_DONE}.
 *
 * The person is named by id, and one session by its row id - or no session at all, which asks for
 * every session the person has when the library gets to it. Who is ASKING is never on the payload:
 * the library reads the administrator off the connection the accept key names.
 *
 * Everything but the person and the session is the administrator waiting, not part of the ending
 * ({@see HandoverAskInterface}). The success sentence is the library's to compose, because only it
 * knows how many sessions it ended.
 */
final class UserSessionsEndRequestSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string sessionId = 'sessionId';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person whose sessions end
     * @param ?int $sessionId Session row to end, or null to end every session of the person
     * @param string $replySignal Agent signal the library answers under
     * @param string $acceptKey Initiating connection accept key, both the asker and whom to answer
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null until the library composes it
     * @throws InvalidFormatException When the person id is not positive, or one session is named by an id that is not
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?int $sessionId,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
        if ($sessionId !== null && $sessionId <= 0) {
            throw new InvalidFormatException('Session id must be positive when one session ends');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Sessions ending handover request
     * @throws InvalidFormatException When the person, the session or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            sessionId: self::optionalInt($data, self::sessionId),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::optionalString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::optionalString($data, self::successMessage),
        );
    }

    /** @return array<string, int|string|null> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::sessionId => $this->sessionId,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}

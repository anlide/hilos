<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
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
 * The accept key and the request id are the admin waiting, not part of the takeover: they
 * travel whole so the page can answer the one connection that asked, on the one request it
 * made. The request id is nullable because an untracked submit correlates nothing.
 */
final class ImpersonateRequestSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param int $targetUserId User id the admin session asks to act as
     * @param string $acceptKey Initiating connection accept key, both the asker and whom to answer
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     */
    public function __construct(
        public readonly int $targetUserId,
        public readonly string $acceptKey,
        public readonly ?string $requestId = null,
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
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no user to impersonate or no connection
     */
    public static function fromArray(array $data): static
    {
        return new static(
            targetUserId: self::requireInt($data, 'targetUserId'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
        );
    }
}

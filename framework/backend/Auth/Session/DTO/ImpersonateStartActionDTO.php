<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Pages\Users\AbstractHilosUsersPage;

/**
 * ImpersonateStartActionDTO - payload for the impersonation-start action (HIL-729).
 *
 * Names the target and nothing else: the acting admin session is read from the connection
 * on the server, so the payload can name whom to become but never who is asking.
 *
 * Owned by {@see AbstractHilosUsersPage} through ACTIONS since HIL-824, for the reason
 * {@see HilosSignalConstants::HILOS_IMPERSONATE_START} gives: only an administrator may take
 * a person over, and an ADMIN level is a thing only a page carries. What the action writes is
 * a session and that is still {@see AbstractSessionsLibraryAgent}'s, so the page forwards the
 * work; whether the asker may is still the project's answer as well, and the library keeps
 * asking for it through a seam, which is what closes the entrance that has no page.
 */
final class ImpersonateStartActionDTO extends ActionPayloadDTO
{
    public const string targetUserId = 'targetUserId';

    /**
     * @param int $targetUserId User id the admin session will impersonate
     */
    public function __construct(
        public readonly int $targetUserId,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_IMPERSONATE_START;
    }

    /**
     * Create from array, unwrapping the optional FIELD_DATA envelope.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Impersonate-start DTO instance
     * @throws InvalidFormatException When the payload names no user to impersonate
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            targetUserId: self::requireInt($inner, self::targetUserId),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Payload with the target user id
     */
    public function toArray(): array
    {
        return [
            self::targetUserId => $this->targetUserId,
        ];
    }
}

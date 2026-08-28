<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Admin page → users library: give this person that name (HIL-771).
 *
 * What {@see HilosSignalConstants::HILOS_USER_ADMIN_RENAME} carries, and the shape an admin
 * submit takes when its two halves have different owners. The page keeps the action, because
 * the ADMIN level closing it exists on a page and an agent action has no such level to inherit;
 * the account row is {@see AbstractUsersLibraryAgent}'s, and a page runs in whichever worker
 * serves the socket, so the page forwards rather than writes.
 *
 * The admin is named here and not looked up on the far side deliberately: the connection lives
 * in the worker that took the submit, so who is asking is answered there - by the page's own
 * session, never by a client value - and travels as a finding rather than as a question.
 *
 * The accept key and the request id are the person waiting: they come back untouched, so the
 * page answers the one connection that asked on the one submit it made.
 */
final class AdminRenameSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param int $userId Account to rename
     * @param string $name Display name the admin typed
     * @param string $acceptKey Initiating connection accept key to answer
     * @param ?string $requestId Client-minted request id of a tracked submit, or null when untracked
     * @param ?int $adminUserId The admin behind the submit, as their own worker resolved them
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly string $acceptKey,
        public readonly ?string $requestId = null,
        public readonly ?int $adminUserId = null,
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
            'userId' => $this->userId,
            'name' => $this->name,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'adminUserId' => $this->adminUserId,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no account, no name or no connection to answer
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, 'userId'),
            name: self::requireString($data, 'name'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            adminUserId: self::optionalInt($data, 'adminUserId'),
        );
    }
}

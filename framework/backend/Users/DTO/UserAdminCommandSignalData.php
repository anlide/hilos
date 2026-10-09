<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Sessions library → the person's agent: write the admin flag an operator's command asked for (HIL-1404).
 *
 * What {@see HilosSignalConstants::HILOS_USER_ADMIN_COMMAND} carries, for
 * {@see CliCommands::ADMIN_GRANT}, {@see CliCommands::ADMIN_REVOKE} and
 * {@see CliCommands::ADMIN_CREATE} over a session that already carries a person. Not an ask of the
 * handover form ({@see HandoverAskInterface}): a command writes outside any connection, so there
 * is nobody to stamp the write with, and an empty accept key would lie in the field the tables read.
 *
 * {@see AbstractUserAgent} writes the flag and sends this request back inside
 * {@see UserAdminCommandDoneSignalData}; {@see AbstractSessionsLibraryAgent} answers the parked
 * command from it. The session token and its expiry are carried by the create command alone: the
 * library binds that session once the flag is written.
 */
final class UserAdminCommandSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string admin = 'admin';
    public const string replySignal = 'replySignal';
    public const string correlationId = 'correlationId';
    public const string command = 'command';
    public const string sessionToken = 'sessionToken';
    public const string expired = 'expired';

    /**
     * @param int $userId Person whose flag is written, and the index of the agent the request is for
     * @param bool $admin Flag to write
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $correlationId Correlation id of the parked command to answer
     * @param string $command Command wire name the request came from
     * @param ?string $sessionToken Session the create command binds afterwards, or null for grant and revoke
     * @param bool $expired Whether the create command found that session expired; false for grant and revoke
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $admin,
        public readonly string $replySignal,
        public readonly string $correlationId,
        public readonly string $command,
        public readonly ?string $sessionToken,
        public readonly bool $expired,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Admin flag request of a command
     * @throws InvalidFormatException When the person, the flag or the parked command is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            admin: self::requireBool($data, self::admin),
            replySignal: self::requireString($data, self::replySignal),
            correlationId: self::requireString($data, self::correlationId),
            command: self::requireString($data, self::command),
            sessionToken: self::optionalString($data, self::sessionToken),
            expired: self::requireBool($data, self::expired),
        );
    }

    /** @return array<string, int|bool|string|null> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::admin => $this->admin,
            self::replySignal => $this->replySignal,
            self::correlationId => $this->correlationId,
            self::command => $this->command,
            self::sessionToken => $this->sessionToken,
            self::expired => $this->expired,
        ];
    }
}

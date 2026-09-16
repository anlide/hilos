<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Router\Destination\SessionClientsDestination;
use Hilos\Core\Router\SignalDataInterface;

/**
 * PageAccessReassessSessionSignalData - the by-session announcement's payload (HIL-911).
 *
 * It carries one browser session where its twins carry a person
 * ({@see PageAccessReassessUserSignalData}) or a list of sockets
 * ({@see PageAccessReassessConnectionsSignalData}), and it never leaves the master: the daemon
 * resolves the session into the accept keys of its own connections and hands the workers the
 * by-connection announcement ({@see PageAccessReassessment::forSession()},
 * {@see DaemonManager}).
 *
 * The hash and never the token, for the reason {@see SessionClientsDestination} gives: the token
 * is the key to the account behind it, while the hash is what a connection is matched on and what
 * the freeze row already records about the browser that asked.
 */
final class PageAccessReassessSessionSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the hash of the session token whose open pages are to be re-judged. */
    public const string sessionTokenHash = 'sessionTokenHash';

    /**
     * @param string $sessionTokenHash Hash of the session token whose open pages are to be re-judged
     */
    public function __construct(
        public readonly string $sessionTokenHash,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::sessionTokenHash => $this->sessionTokenHash,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no session
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sessionTokenHash: self::requireString($data, self::sessionTokenHash),
        );
    }
}

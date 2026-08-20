<?php

declare(strict_types=1);

namespace Demo\Tasks\Core\Router\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;

/**
 * GuestIdentitySignalData - the name a visitor without an account is known by (HIL-610).
 *
 * Sent to the accept-key of a handshaking anonymous socket, just before the
 * framework handshake response that will tell it it has no user. The two together
 * are what the identity line on the main page is drawn from: the framework answers
 * who the account is, this answers what to call a browser that has none.
 *
 * A project signal and not an envelope-bearing ack: nothing asked for it, so there
 * is no action to report an outcome on.
 */
final class GuestIdentitySignalData extends SignalData implements SignalDataInterface
{
    public const string NAME = 'name';

    /**
     * @param string $name Display name of the guest behind this socket's session
     */
    public function __construct(
        public readonly string $name,
    ) {
        parent::__construct([self::NAME => $name]);
    }

    /**
     * Roundtrip reconstruction used when the signal crosses the worker -> daemon IPC boundary.
     *
     * @param array<string, mixed> $data Serialized payload
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no guest name
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::NAME));
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle\DTO;

use Hilos\Auth\Throttle\ThrottleScope;
use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ThrottleSuccessSignalData - a session proved who it is (HIL-420).
 *
 * The only thing the counters need to hear about a successful authentication: which session
 * it was. Everything counted against that session - on any action, at any ladder level - is
 * then forgiven, because what those attempts were suspected of is exactly what has just been
 * settled.
 *
 * There is no scope on the payload and no action: the reset is {@see ThrottleScope::SESSION}
 * by definition. An IP is never cleared this way, since one legitimate sign-in from behind a
 * NAT would otherwise lift the pressure every other client on that address built up.
 */
final class ThrottleSuccessSignalData extends BaseDTO implements SignalDataInterface
{
    public const string identity = 'identity';

    /**
     * @param string $identity Digest of the session token that authenticated
     */
    public function __construct(
        public readonly string $identity,
    ) {
    }

    /**
     * @return array<string, mixed> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::identity => $this->identity,
        ];
    }

    /**
     * Reads the identity without a fallback: a reset that names no session would clear
     * whatever the empty key happens to address.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            identity: (string)$data[self::identity],
        );
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketLivenessSignalDTO - DTO for the liveness transition of one WebSocket connection (HIL-1566).
 *
 * Built by the master socket of the connection and never out of a browser frame: the socket
 * names its own accept key and the state it just moved into, so a browser chooses neither which
 * connection is doubted nor whether it is.
 */
class WebSocketLivenessSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PROBABLY_OFFLINE = 'probablyOffline';

    /**
     * Creates WebSocket liveness signal DTO.
     *
     * @param string $acceptKey WebSocket accept key of the connection that moved
     * @param bool $probablyOffline Whether the socket went silent past its liveness window
     * @throws InvalidFormatException When the accept key is empty
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly bool $probablyOffline,
    ) {
        if ($acceptKey === '') {
            throw new InvalidFormatException('Connection liveness requires a nonempty accept key');
        }
    }

    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, string|bool> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::ACCEPT_KEY => $this->acceptKey,
            self::PROBABLY_OFFLINE => $this->probablyOffline,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the accept key is absent, not a string or empty, or the state is absent or not a boolean
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
            probablyOffline: self::requireBool($data, self::PROBABLY_OFFLINE),
        );
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketActionSignalDTO - DTO for WebSocket action signal.
 *
 * Represents an action signal sent from WebSocket client.
 *
 * Two of its fields are stamped by the master rather than sent by the client:
 * {@see clientIp} and {@see sessionIdentity} name who is asking, so the anti-abuse
 * guard can key an attempt without a lookup table from accept key to connection
 * (HIL-420). They travel on the master→worker leg only; nothing about the frame the
 * browser sends changes, and a client cannot set them because the frame parser does
 * not read them.
 */
class WebSocketActionSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string ACTION = 'action';
    public const string DATA = 'data';
    public const string REQUEST_ID = 'requestId';
    public const string CLIENT_IP = 'clientIp';
    public const string SESSION_IDENTITY = 'sessionIdentity';

    /**
     * Creates WebSocket action signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param array<string, mixed> $data Action payload data
     * @param ?string $requestId Client-minted request id for reply correlation, or null for a fire-and-forget action
     * @param ?string $clientIp Peer address the connection was accepted from, or null when it is unavailable
     * @param ?string $sessionIdentity Digest of the connection's session token, or null when it carries no session
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $action,
        public readonly array $data = [],
        public readonly ?string $requestId = null,
        public readonly ?string $clientIp = null,
        public readonly ?string $sessionIdentity = null,
    ) {
    }

    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        $result = [
            self::ACCEPT_KEY => $this->acceptKey,
            self::ACTION => $this->action,
        ];

        if (!empty($this->data)) {
            $result[self::DATA] = $this->data;
        }

        if ($this->requestId !== null) {
            $result[self::REQUEST_ID] = $this->requestId;
        }

        if ($this->clientIp !== null) {
            $result[self::CLIENT_IP] = $this->clientIp;
        }

        if ($this->sessionIdentity !== null) {
            $result[self::SESSION_IDENTITY] = $this->sessionIdentity;
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * The action arguments stay optional: toArray() leaves the key out when the
     * action takes none, so an absent section reads as the empty one it was.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no accept key or no action name
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
            action: self::requireString($data, self::ACTION),
            data: self::optionalArray($data, self::DATA) ?? [],
            requestId: self::optionalString($data, self::REQUEST_ID),
            clientIp: self::optionalString($data, self::CLIENT_IP),
            sessionIdentity: self::optionalString($data, self::SESSION_IDENTITY),
        );
    }
}

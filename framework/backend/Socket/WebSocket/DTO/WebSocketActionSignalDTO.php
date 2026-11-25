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
 * The master stamps {@see clientIp} and {@see sessionIdentity} for anti-abuse
 * checks (HIL-420) and {@see pageSubscriptionDeliveryId} for an action carrying
 * a proof. These values travel to the worker and cannot be supplied by a browser.
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

    private const string pageSubscriptionDeliveryId = 'pageSubscriptionDeliveryId';

    /**
     * Creates WebSocket action signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param array<string, mixed> $data Action payload data
     * @param ?string $requestId Client-minted request id for reply correlation, or null for a fire-and-forget action
     * @param ?string $clientIp Peer address the connection was accepted from, or null when it is unavailable
     * @param ?string $sessionIdentity Digest of the connection's session token, or null when it carries no session
     * @param ?string $pageSubscriptionProof Client's proof from an accepted page response
     * @param ?string $pageSubscriptionDeliveryId Internal authority of the socket when it received a proof
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $action,
        public readonly array $data = [],
        public readonly ?string $requestId = null,
        public readonly ?string $clientIp = null,
        public readonly ?string $sessionIdentity = null,
        public readonly ?string $pageSubscriptionProof = null,
        public readonly ?string $pageSubscriptionDeliveryId = null,
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
        if ($this->pageSubscriptionProof !== null) {
            $result[PageSubscriptionProof::pageSubscriptionProof] = $this->pageSubscriptionProof;
        }
        if ($this->pageSubscriptionDeliveryId !== null) {
            $result[self::pageSubscriptionDeliveryId] = $this->pageSubscriptionDeliveryId;
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
     * @throws InvalidFormatException When a required address is missing or trusted IPC metadata has the wrong type
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
            pageSubscriptionProof: PageSubscriptionProof::read($data[PageSubscriptionProof::pageSubscriptionProof] ?? null),
            pageSubscriptionDeliveryId: self::optionalString($data, self::pageSubscriptionDeliveryId),
        );
    }
}

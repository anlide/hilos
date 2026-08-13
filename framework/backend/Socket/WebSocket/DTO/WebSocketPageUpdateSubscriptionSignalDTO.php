<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketPageUpdateSubscriptionSignalDTO - DTO for WebSocket page update subscription signal.
 *
 * Represents a page subscription update signal sent from WebSocket client.
 */
class WebSocketPageUpdateSubscriptionSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string PARAMS = 'params';

    /**
     * Creates page update subscription signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param ?string $page Page name to update subscription for, null when the signal name carries it
     * @param array<string, string> $params Route params for the page
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $page = null,
        public readonly array $params = [],
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
        ];

        if ($this->page !== null) {
            $result[self::PAGE] = $this->page;
        }

        if (!empty($this->params)) {
            $result[self::PARAMS] = $this->params;
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * The page and the params stay optional: toArray() leaves either key out
     * when it has nothing to write, so an absent one reads as the absence it was.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no accept key
     */
    public static function fromArray(array $data): static
    {
        $page = self::optionalString($data, self::PAGE);

        return new static(
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
            page: $page === '' ? null : $page,
            params: self::optionalArray($data, self::PARAMS) ?? [],
        );
    }
}

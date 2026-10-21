<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/** Internal signal payload marking the end of one page delivery. */
final class DeliveryCompleteSignalData extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    public const string PAGE = 'page';
    public const string DELIVERY_ID = 'deliveryId';

    /**
     * @param string $page Completed page name
     * @param string $deliveryId Delivery token to complete
     * @throws InvalidFormatException When either required value is empty
     */
    public function __construct(
        public readonly string $page,
        public readonly string $deliveryId,
    ) {
        if ($page === '' || $deliveryId === '') {
            throw new InvalidFormatException('Delivery completion requires a nonempty page and delivery id');
        }
    }

    /**
     * @return array<string, mixed> Completion payload
     */
    public function toArray(): array
    {
        return [
            self::PAGE => $this->page,
            self::DELIVERY_ID => $this->deliveryId,
        ];
    }

    /**
     * @param array<string, mixed> $data Completion payload
     * @return static Restored completion payload
     * @throws InvalidFormatException When a required value is absent, mistyped or empty
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: self::requireString($data, self::PAGE),
            deliveryId: self::requireString($data, self::DELIVERY_ID),
        );
    }
}

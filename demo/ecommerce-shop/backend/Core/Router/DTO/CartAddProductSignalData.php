<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\HilosSessionConnection;

/** Product page → the owner of the acting session's cart. */
final class CartAddProductSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string CART_NOT_READY = 'Your cart is not ready. Please try again.';

    private const string replySignal = 'replySignal';
    private const string successMessage = 'successMessage';

    /**
     * @param int $productId Primary key verified against the open card
     * @param string $productSlug Original index of the product page's agent
     * @param int $sessionId Acting connection's session at the handover
     * @param ?int $userId Acting person and account owner index, or null for a guest
     * @param string $replySignal Signal on which to return the outcome
     * @param string $acceptKey Initiating connection and write origin
     * @param ?string $requestId Client correlation id, or null for an untracked click
     * @param string $action Action whose outcome is answered
     * @param ?string $successMessage Success sentence, or null for a silent success
     * @throws InvalidFormatException When an id is not positive or the product address is empty
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $productSlug,
        public readonly int $sessionId,
        public readonly ?int $userId,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($productId <= 0 || $sessionId <= 0 || ($userId !== null && $userId <= 0)) {
            throw new InvalidFormatException('Product, session and user IDs must be positive integers.');
        }
        if ($productSlug === '') {
            throw new InvalidFormatException('The original product address is required.');
        }
    }

    /**
     * @param array<string, mixed> $data Product, cart identity and handover address
     * @return static Add request with the original identity and correlation
     * @throws InvalidFormatException When a product, identity or handover field is invalid
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists(AccountCartSendSignalData::userId, $data)) {
            throw new InvalidFormatException('Payload carries no user id field');
        }

        return new static(
            productId: self::requireInt($data, CartStateSignalData::PRODUCT_ID),
            productSlug: self::requireString($data, CartAddProductDoneSignalData::productSlug),
            sessionId: self::requireInt($data, HilosSessionConnection::sessionId),
            userId: self::optionalInt($data, AccountCartSendSignalData::userId),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, SignalPayloadConstants::FIELD_ACCEPT_KEY),
            requestId: self::optionalString($data, SignalPayloadConstants::FIELD_REQUEST_ID),
            action: self::requireString($data, SignalPayloadConstants::FIELD_ACTION),
            successMessage: self::optionalString($data, self::successMessage),
        );
    }

    /** @return array<string, mixed> Transport payload and original handover address */
    public function toArray(): array
    {
        return [
            CartStateSignalData::PRODUCT_ID => $this->productId,
            CartAddProductDoneSignalData::productSlug => $this->productSlug,
            HilosSessionConnection::sessionId => $this->sessionId,
            AccountCartSendSignalData::userId => $this->userId,
            self::replySignal => $this->replySignal,
            SignalPayloadConstants::FIELD_ACCEPT_KEY => $this->acceptKey,
            SignalPayloadConstants::FIELD_REQUEST_ID => $this->requestId,
            SignalPayloadConstants::FIELD_ACTION => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}

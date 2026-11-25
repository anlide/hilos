<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\DTO;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\PageSubscriptionProofAction;

/** The saved voucher ID and owner kind from the cart displayed to the shopper. */
final class CartVoucherRemoveActionDTO extends ActionPayloadDTO implements PageSubscriptionProofAction
{
    public const array SECRET_FIELDS = [];

    private const string expectedVoucherId = 'expectedVoucherId';
    private const string ownerKind = 'ownerKind';

    public private(set) ?string $pageSubscriptionProof = null;
    public private(set) ?string $pageSubscriptionDeliveryId = null;

    /**
     * @param int $expectedVoucherId Primary key of the displayed voucher
     * @param string $ownerKind Kind of cart the page displayed
     * @throws InvalidFormatException When the voucher ID or cart kind is invalid
     */
    public function __construct(public readonly int $expectedVoucherId, public readonly string $ownerKind)
    {
        if ($expectedVoucherId <= 0 || $expectedVoucherId > CartStateSignalData::MAX_SAFE_VOUCHER_ID) {
            throw new InvalidFormatException('Payload carries no positive safe integer under key ' . self::expectedVoucherId);
        }
        if ($ownerKind !== CartStepQuantityActionDTO::GUEST && $ownerKind !== CartStepQuantityActionDTO::ACCOUNT) {
            throw new InvalidFormatException('Unknown cart owner kind');
        }
    }

    /** @return string Remove voucher action name */
    public function getAction(): string
    {
        return EcommerceShopSignalConstants::CART_REMOVE_VOUCHER;
    }

    /**
     * @param ?string $pageSubscriptionProof Proof from the original action envelope
     * @param ?string $deliveryId Subscription authority attested by the receiving socket
     * @return static Removal addressed by the displayed snapshot
     */
    public function withPageSubscriptionProof(?string $pageSubscriptionProof, ?string $deliveryId): static
    {
        $removal = clone $this;
        $removal->pageSubscriptionProof = $pageSubscriptionProof;
        $removal->pageSubscriptionDeliveryId = $deliveryId;

        return $removal;
    }

    /**
     * @param array<string, mixed> $data Action payload, optionally wrapped
     * @return static Parsed action
     * @throws InvalidFormatException When the wrapper or either required field is invalid
     */
    public static function fromArray(array $data): static
    {
        $inner = array_key_exists(SignalPayloadConstants::FIELD_DATA, $data)
            ? self::requireArray($data, SignalPayloadConstants::FIELD_DATA)
            : $data;

        return new static(
            self::requireInt($inner, self::expectedVoucherId),
            self::requireString($inner, self::ownerKind),
        );
    }

    /** @return array{expectedVoucherId: int, ownerKind: string} Voucher removal payload */
    public function toArray(): array
    {
        return [
            self::expectedVoucherId => $this->expectedVoucherId,
            self::ownerKind => $this->ownerKind,
        ];
    }
}

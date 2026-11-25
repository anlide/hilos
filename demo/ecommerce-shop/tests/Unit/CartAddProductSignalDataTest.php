<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductDoneSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductSignalData;
use Demo\EcommerceShop\Pages\DTO\ProductAddToCartActionDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CartAddProductSignalDataTest extends TestCase
{
    public function testActionCarriesOnlyTheProductPrimaryKey(): void
    {
        $data = ['productId' => 31];
        $wrapped = ProductAddToCartActionDTO::fromArray([SignalPayloadConstants::FIELD_DATA => $data]);

        self::assertSame('product_add_to_cart', $wrapped->getAction());
        self::assertSame([], ProductAddToCartActionDTO::SECRET_FIELDS);
        self::assertSame($data, $wrapped->toArray());
        self::assertEquals($wrapped, ProductAddToCartActionDTO::fromJson($wrapped->toJson()));
    }

    /** @param array<string, mixed> $payload Malformed browser action */
    #[DataProvider('invalidActions')]
    public function testActionRequiresAPositiveIntegerProductId(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        ProductAddToCartActionDTO::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Payloads rejected before routing the write */
    public static function invalidActions(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['productId' => null]];
        yield 'string' => [['productId' => '31']];
        yield 'float' => [['productId' => 31.0]];
        yield 'bool' => [['productId' => true]];
        yield 'zero' => [['productId' => 0]];
        yield 'negative' => [['productId' => -31]];
        yield 'malformed wrapper' => [[SignalPayloadConstants::FIELD_DATA => 31]];
    }

    public function testHandoverRetainsBothKindsOfCartAddress(): void
    {
        foreach ([null, 92] as $userId) {
            $data = self::askPayload($userId);
            $ask = CartAddProductSignalData::fromArray($data);

            self::assertInstanceOf(HandoverAskInterface::class, $ask);
            self::assertSame($userId, $ask->userId);
            self::assertSame($userId === null ? 3 : null, $ask->cartEpoch);
            self::assertSame($data, $ask->toArray());
            self::assertEquals($ask, CartAddProductSignalData::fromJson($ask->toJson()));
        }
    }

    public function testGuestHandoverRequiresTheEpochField(): void
    {
        $payload = self::askPayload(null);
        unset($payload['cartEpoch']);

        $this->expectException(InvalidFormatException::class);
        CartAddProductSignalData::fromArray($payload);
    }

    /** @param array<string, mixed> $fields Invalid product or session identity fields */
    #[DataProvider('invalidAddresses')]
    public function testHandoverRefusesMalformedAddresses(array $fields): void
    {
        $this->expectException(InvalidFormatException::class);
        CartAddProductSignalData::fromArray(array_replace(self::askPayload(null), $fields));
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid address fields */
    public static function invalidAddresses(): iterable
    {
        yield 'empty slug' => [['productSlug' => '']];
        yield 'non-string slug' => [['productSlug' => 31]];
        yield 'nonpositive product' => [['productId' => 0]];
        yield 'nonpositive session' => [['sessionId' => -1]];
        yield 'string session' => [['sessionId' => '82']];
        yield 'negative epoch' => [['cartEpoch' => -1]];
        yield 'string epoch' => [['cartEpoch' => '3']];
        yield 'float epoch' => [['cartEpoch' => 3.0]];
        yield 'null epoch' => [['cartEpoch' => null]];
        yield 'nonpositive account' => [['userId' => 0]];
        yield 'string account' => [['userId' => '92']];
    }

    public function testAnswerCarriesTheOriginalAddressAndCorrelation(): void
    {
        $ask = CartAddProductSignalData::fromArray(self::askPayload(92));
        $reason = 'There are not enough units in stock.';
        foreach ([null, ActionRefusal::said($reason)] as $refusal) {
            $answer = HandoverAnswerSignalData::to($ask, $refusal);
            $done = new CartAddProductDoneSignalData($ask->productSlug, $answer);

            self::assertSame([
                'productSlug' => 'old-product-address',
                'answer' => [
                    'acceptKey' => 'clicking-tab',
                    'requestId' => 'click-1',
                    'action' => EcommerceShopSignalConstants::PRODUCT_ADD_TO_CART,
                    'successMessage' => null,
                    'error' => $refusal?->reason,
                    'errorType' => null,
                    'errorDetail' => null,
                ],
            ], $done->toArray());
            self::assertEquals($done, CartAddProductDoneSignalData::fromJson($done->toJson()));
        }
    }

    /** @return array<string, mixed> Cart add handover as carried on the wire */
    private static function askPayload(?int $userId): array
    {
        $data = [
            'productId' => 31,
            'productSlug' => 'old-product-address',
            'sessionId' => 82,
            'userId' => $userId,
            'replySignal' => EcommerceShopSignalConstants::CART_PRODUCT_ADD_DONE,
            'acceptKey' => 'clicking-tab',
            'requestId' => 'click-1',
            'action' => EcommerceShopSignalConstants::PRODUCT_ADD_TO_CART,
            'successMessage' => null,
        ];
        if ($userId === null) {
            $data['cartEpoch'] = 3;
        }

        return $data;
    }
}

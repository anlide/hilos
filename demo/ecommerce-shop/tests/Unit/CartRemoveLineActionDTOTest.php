<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Pages\DTO\CartRemoveLineActionDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The removal payload identifies one saved line of the cart the shopper saw. */
final class CartRemoveLineActionDTOTest extends TestCase
{
    private const array INPUT = ['lineId' => 17, 'ownerKind' => 'guest'];

    public function testBothCartKindsRoundTrip(): void
    {
        foreach ([self::INPUT, [...self::INPUT, 'ownerKind' => 'account']] as $input) {
            $dto = CartRemoveLineActionDTO::fromArray([SignalPayloadConstants::FIELD_DATA => $input]);
            self::assertSame('cart_remove_line', $dto->getAction());
            self::assertSame([], CartRemoveLineActionDTO::SECRET_FIELDS);
            self::assertSame($input, $dto->toArray());
            self::assertEquals($dto, CartRemoveLineActionDTO::fromArray($dto->toArray()));
        }
    }

    /** @param array<string, mixed> $input Malformed cart removal payload */
    #[DataProvider('invalidInputs')]
    public function testAnInvalidPayloadIsRefused(array $input): void
    {
        $this->expectException(InvalidFormatException::class);

        CartRemoveLineActionDTO::fromArray($input);
    }

    /** @return iterable<string, array{array<string, mixed>}> Refused removal payloads */
    public static function invalidInputs(): iterable
    {
        yield 'missing line ID' => [['ownerKind' => 'guest']];
        yield 'missing owner kind' => [['lineId' => 17]];
        yield 'owner and product IDs instead of line ID' => [['ownerKind' => 'account', 'userId' => 1, 'sessionId' => 2, 'productId' => 3]];
        yield 'non-object payload' => [[SignalPayloadConstants::FIELD_DATA => 'guest']];
        yield 'zero line ID' => [[...self::INPUT, 'lineId' => 0]];
        yield 'negative line ID' => [[...self::INPUT, 'lineId' => -17]];
        yield 'numeric string ID' => [[...self::INPUT, 'lineId' => '17']];
        yield 'floating-point ID' => [[...self::INPUT, 'lineId' => 17.0]];
        yield 'null line ID' => [[...self::INPUT, 'lineId' => null]];
        yield 'unknown owner kind' => [[...self::INPUT, 'ownerKind' => 'anonymous']];
        yield 'numeric owner kind' => [[...self::INPUT, 'ownerKind' => 1]];
        yield 'null owner kind' => [[...self::INPUT, 'ownerKind' => null]];
    }
}

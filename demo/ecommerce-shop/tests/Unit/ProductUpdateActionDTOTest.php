<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Pages\DTO\ProductUpdateActionDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The tracked product edit accepts the catalog fields and an explicit choice about its picture. */
final class ProductUpdateActionDTOTest extends TestCase
{
    /** @var array{productId: int, expectedVersion: string, name: string, categoryId: int,
     *     price: string, description: string, pictureFileId: int}
     */
    private const array INPUT = [
        'productId' => 17,
        'expectedVersion' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        'name' => 'Glazed vase',
        'categoryId' => 8,
        'price' => '2 100,50',
        'description' => 'Hand-thrown stoneware',
        'pictureFileId' => 91,
    ];

    public function testTheRawFieldsSurviveARoundTrip(): void
    {
        $dto = ProductUpdateActionDTO::fromArray([SignalPayloadConstants::FIELD_DATA => self::INPUT]);
        self::assertSame('product_update', $dto->getAction());
        self::assertSame([], ProductUpdateActionDTO::SECRET_FIELDS);
        self::assertSame(self::INPUT, $dto->toArray());
        self::assertEquals($dto, ProductUpdateActionDTO::fromArray($dto->toArray()));
    }

    public function testAnExplicitNullPictureIsPreserved(): void
    {
        $input = [...self::INPUT, 'pictureFileId' => null];
        $dto = ProductUpdateActionDTO::fromArray($input);

        self::assertNull($dto->pictureFileId);
        self::assertSame($input, $dto->toArray());
    }

    /** @param array<string, int|string|null> $input Malformed product edit payload */
    #[DataProvider('invalidInputs')]
    public function testAnInvalidPayloadIsRefused(array $input): void
    {
        $this->expectException(InvalidFormatException::class);

        ProductUpdateActionDTO::fromArray($input);
    }

    /** @return iterable<string, array{array<string, int|string|null>}> Refused edit payloads */
    public static function invalidInputs(): iterable
    {
        foreach (self::INPUT as $field => $value) {
            $input = self::INPUT;
            unset($input[$field]);
            yield 'missing ' . $field => [$input];
        }
        yield 'uppercase version' => [[...self::INPUT, 'expectedVersion' => strtoupper(self::INPUT['expectedVersion'])]];
        yield 'short version' => [[...self::INPUT, 'expectedVersion' => '123']];
        yield 'version with trailing newline' => [[...self::INPUT, 'expectedVersion' => self::INPUT['expectedVersion'] . "\n"]];
        yield 'product ID zero' => [[...self::INPUT, 'productId' => 0]];
        yield 'negative category ID' => [[...self::INPUT, 'categoryId' => -8]];
        yield 'numeric price' => [[...self::INPUT, 'price' => 2100]];
        yield 'string picture ID' => [[...self::INPUT, 'pictureFileId' => '91']];
        yield 'kind change' => [[...self::INPUT, 'kind' => 'unique']];
        yield 'kind supplied as null' => [[...self::INPUT, 'kind' => null]];
    }
}

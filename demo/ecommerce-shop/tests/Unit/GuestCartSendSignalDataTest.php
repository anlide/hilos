<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Core\Router\DTO\GuestCartSendSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GuestCartSendSignalDataTest extends TestCase
{
    /** The worker-to-worker request carries both parts of the address. */
    public function testRoundTrip(): void
    {
        $data = ['sessionId' => 82, 'cartEpoch' => 0];
        $ask = new GuestCartSendSignalData(82, 0);

        self::assertSame($data, $ask->toArray());
        self::assertEquals($ask, GuestCartSendSignalData::fromArray($data));
        self::assertEquals($ask, GuestCartSendSignalData::fromJson($ask->toJson()));
    }

    /** @param array<string, mixed> $data Malformed session or epoch */
    #[DataProvider('invalidCoordinates')]
    public function testInvalidCoordinateIsRefused(array $data): void
    {
        $this->expectException(InvalidFormatException::class);
        GuestCartSendSignalData::fromArray($data);
    }

    /** @return iterable<string, array{array<string, mixed>}> Missing or invalid coordinates */
    public static function invalidCoordinates(): iterable
    {
        yield 'missing epoch' => [['sessionId' => 82]];
        yield 'missing session' => [['cartEpoch' => 0]];
        yield 'zero session' => [['sessionId' => 0, 'cartEpoch' => 0]];
        yield 'negative epoch' => [['sessionId' => 82, 'cartEpoch' => -1]];
        yield 'string epoch' => [['sessionId' => 82, 'cartEpoch' => '0']];
        yield 'float epoch' => [['sessionId' => 82, 'cartEpoch' => 0.0]];
        yield 'null epoch' => [['sessionId' => 82, 'cartEpoch' => null]];
    }
}

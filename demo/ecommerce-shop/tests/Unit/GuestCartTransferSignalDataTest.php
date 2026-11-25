<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferClearedSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferOfferSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferReceivedSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferWakeSignalData;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\AgentSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GuestCartTransferSignalDataTest extends TestCase
{
    /**
     * @param class-string<BaseDTO> $dto Frame class
     * @param array<string, mixed> $payload Transport fields
     */
    #[DataProvider('frames')]
    public function testFrameSurvivesTheAgentEnvelope(string $dto, array $payload): void
    {
        $frame = $dto::fromArray($payload);
        $received = AgentSignalData::fromJson((new AgentSignalData($frame))->toJson())->data;

        self::assertInstanceOf($dto, $received);
        self::assertSame($payload, $received->toArray());
    }

    /** @return iterable<string, array{class-string<BaseDTO>, array<string, mixed>}> Valid transport frames */
    public static function frames(): iterable
    {
        yield 'wake' => [GuestCartTransferWakeSignalData::class, ['sessionId' => 82]];
        yield 'offer' => [GuestCartTransferOfferSignalData::class, ['transferId' => 50, 'userId' => 92]];
        yield 'received' => [GuestCartTransferReceivedSignalData::class, ['transferId' => 50, 'userId' => 92]];
        yield 'cleared' => [GuestCartTransferClearedSignalData::class, ['transferId' => 50, 'userId' => 92]];
    }

    /**
     * @param class-string<BaseDTO> $dto Frame class
     * @param array<string, mixed> $payload Malformed transport fields
     */
    #[DataProvider('invalidAddresses')]
    public function testFrameRequiresPositiveIntegerAddresses(string $dto, array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        $dto::fromArray($payload);
    }

    /** @return iterable<string, array{class-string<BaseDTO>, array<string, mixed>}> Invalid address fields */
    public static function invalidAddresses(): iterable
    {
        foreach (self::frames() as $name => [$dto, $payload]) {
            $fields = $dto === GuestCartTransferWakeSignalData::class ? ['sessionId'] : ['transferId', 'userId'];
            foreach ($fields as $field) {
                $missing = $payload;
                unset($missing[$field]);
                yield "{$name} missing {$field}" => [$dto, $missing];
                foreach (['zero' => 0, 'negative' => -1, 'string' => '82', 'float' => 82.0, 'bool' => true, 'null' => null] as $kind => $value) {
                    yield "{$name} {$field} {$kind}" => [$dto, array_replace($payload, [$field => $value])];
                }
            }
        }
    }
}

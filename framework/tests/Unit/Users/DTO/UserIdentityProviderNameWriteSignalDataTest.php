<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\DTO\UserIdentityProviderNameWriteSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserIdentityProviderNameWriteSignalDataTest extends TestCase
{
    public function testRoundtripPreservesTheCapturedIdsAndLiteralName(): void
    {
        $payload = ['userId' => 42, 'identityId' => 91, 'providerName' => ' 李 | Łukasz 👩‍💻 '];
        $report = UserIdentityProviderNameWriteSignalData::fromArray($payload);

        self::assertSame($payload, $report->toArray());
        self::assertEquals($report, UserIdentityProviderNameWriteSignalData::fromJson($report->toJson()));
    }

    /** @param array<string, mixed> $payload Missing, mistyped or nonpositive report fields */
    #[DataProvider('invalidPayloads')]
    public function testMalformedReportsAreRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        UserIdentityProviderNameWriteSignalData::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid transport payloads */
    public static function invalidPayloads(): iterable
    {
        $valid = ['userId' => 42, 'identityId' => 91, 'providerName' => 'Name'];
        foreach (['userId', 'identityId', 'providerName'] as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield "missing {$key}" => [$missing];
        }
        foreach (['userId', 'identityId'] as $key) {
            foreach ([0, -1, '42', 42.0, true, false, null, []] as $i => $value) {
                yield "invalid {$key} {$i}" => [array_replace($valid, [$key => $value])];
            }
        }
        foreach ([42, 1.5, true, false, null, []] as $i => $value) {
            yield "invalid name {$i}" => [array_replace($valid, ['providerName' => $value])];
        }
    }

    public function testBlankNameIsInputForTheOwnersNoOpGuard(): void
    {
        self::assertSame('  ', UserIdentityProviderNameWriteSignalData::fromArray([
            'userId' => 42, 'identityId' => 91, 'providerName' => '  ',
        ])->providerName);
    }

    public function testConstructorAlsoRefusesANonpositiveUserId(): void
    {
        $this->expectException(InvalidFormatException::class);
        new UserIdentityProviderNameWriteSignalData(0, 91, 'Name');
    }

    public function testConstructorAlsoRefusesANonpositiveIdentityId(): void
    {
        $this->expectException(InvalidFormatException::class);
        new UserIdentityProviderNameWriteSignalData(42, 0, 'Name');
    }
}

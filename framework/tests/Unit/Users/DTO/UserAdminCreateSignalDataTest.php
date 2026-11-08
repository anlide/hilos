<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\DatabaseException;
use Hilos\Users\DTO\UserAdminCreateSignalData;
use Hilos\Users\DTO\UserAdminCreateDoneSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserAdminCreateSignalDataTest extends TestCase
{
    public function testTheRequestAndCreatedAnswerRoundTripThroughJson(): void
    {
        $ask = new UserAdminCreateSignalData('corr-1', 'token-1', true);
        self::assertEquals($ask, UserAdminCreateSignalData::fromJson($ask->toJson()));
        $done = UserAdminCreateDoneSignalData::created($ask, 42);
        $restored = UserAdminCreateDoneSignalData::fromJson($done->toJson());
        self::assertEquals($done, $restored);
        self::assertSame(42, $restored->userId);
        self::assertNull($restored->error);
        self::assertNull($restored->errorType);
        self::assertNull($restored->errorDetail);
        self::assertSame($ask->toArray(), $restored->request->toArray());
    }

    public function testRefusalsKeepTheirWordsAndInternalDetails(): void
    {
        $ask = new UserAdminCreateSignalData('corr-1', 'token-1', false);
        $plain = UserAdminCreateDoneSignalData::refused($ask, ActionRefusal::said('Creation refused'));
        self::assertEquals($plain, UserAdminCreateDoneSignalData::fromArray($plain->toArray()));
        self::assertNull($plain->userId);
        self::assertSame('Creation refused', $plain->error);
        self::assertNull($plain->errorType);
        self::assertNull($plain->errorDetail);
        $internal = UserAdminCreateDoneSignalData::refused($ask, ActionRefusal::fromThrowable(new DatabaseException('disk full')));
        self::assertEquals($internal, UserAdminCreateDoneSignalData::fromJson($internal->toJson()));
        self::assertSame('DatabaseException', $internal->errorType);
        self::assertSame('disk full', $internal->errorDetail);
    }

    /**
     * @param string $correlationId Command identity
     * @param string $token Session identity
     */
    #[DataProvider('emptyAddresses')]
    public function testEmptyAddressesAreRefused(string $correlationId, string $token): void
    {
        $this->expectException(InvalidFormatException::class);
        new UserAdminCreateSignalData($correlationId, $token, false);
    }

    /** @return iterable<string, array{string, string}> Invalid request addresses */
    public static function emptyAddresses(): iterable
    {
        yield 'command' => ['', 'token-1'];
        yield 'session' => ['corr-1', ''];
    }

    /**
     * @param ?int $userId Created id
     * @param ?string $error Refusal
     */
    #[DataProvider('invalidOutcomes')]
    public function testTheConstructorRefusesAmbiguousOrInvalidOutcomes(?int $userId, ?string $error): void
    {
        $this->expectException(InvalidFormatException::class);
        new UserAdminCreateDoneSignalData(new UserAdminCreateSignalData('corr-1', 'token-1', false), $userId, $error, null, null);
    }

    /** @return iterable<string, array{?int, ?string}> Invalid outcomes */
    public static function invalidOutcomes(): iterable
    {
        yield 'neither' => [null, null];
        yield 'both' => [42, 'refused'];
        yield 'zero' => [0, null];
        yield 'negative' => [-1, null];
    }

    /** @param array<string, mixed> $payload Malformed request payload */
    #[DataProvider('malformedRequests')]
    public function testRequestParsingRefusesMissingOrMistypedFields(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        UserAdminCreateSignalData::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed requests */
    public static function malformedRequests(): iterable
    {
        $valid = ['correlationId' => 'corr-1', 'sessionToken' => 'token-1', 'expired' => false];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
            $mistyped = $valid;
            $mistyped[$key] = 1;
            yield 'mistyped ' . $key => [$mistyped];
        }
    }

    /** @param array<string, mixed> $payload Malformed answer payload */
    #[DataProvider('malformedAnswers')]
    public function testAnswerParsingRefusesMistypedFields(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        UserAdminCreateDoneSignalData::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed answers */
    public static function malformedAnswers(): iterable
    {
        $valid = UserAdminCreateDoneSignalData::created(new UserAdminCreateSignalData('corr-1', 'token-1', false), 42)->toArray();
        $missingRequest = $valid;
        unset($missingRequest['request']);
        yield 'missing request' => [$missingRequest];
        foreach (['request' => 'bad', 'userId' => '42', 'error' => false, 'errorType' => [], 'errorDetail' => 1] as $key => $bad) {
            $mistyped = $valid;
            $mistyped[$key] = $bad;
            yield $key => [$mistyped];
        }
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\DatabaseException;
use Hilos\Users\DTO\UserLegalHoldForgetDoneSignalData;
use Hilos\Users\DTO\UserLegalHoldForgetSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserLegalHoldForgetSignalDataTest extends TestCase
{
    public function testRequestAndSuccessfulAnswerSurviveTwoWireHops(): void
    {
        $request = new UserLegalHoldForgetSignalData(
            7, 'terms', 'first', HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET_DONE, 'corr-1',
        );
        $wire = [
            'userId' => 7,
            'document' => 'terms',
            'revisionId' => 'first',
            'replySignal' => HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET_DONE,
            'correlationId' => 'corr-1',
        ];
        self::assertSame($wire, $request->toArray());
        for ($hop = 0; $hop < 2; ++$hop) {
            $request = UserLegalHoldForgetSignalData::fromArray(self::overTheWire($request->toArray()));
            self::assertSame($wire, $request->toArray());
        }
        $done = UserLegalHoldForgetDoneSignalData::ok($request);
        $expected = ['request' => $wire, 'error' => null, 'errorType' => null, 'errorDetail' => null];
        for ($hop = 0; $hop < 2; ++$hop) {
            $done = UserLegalHoldForgetDoneSignalData::fromArray(self::overTheWire($done->toArray()));
            self::assertSame($expected, $done->toArray());
        }
    }

    public function testRefusedAnswerKeepsRequestReasonAndInternalDetailThroughTwoHops(): void
    {
        $request = new UserLegalHoldForgetSignalData(
            7, 'privacy', 'old', HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET_DONE, 'corr-2',
        );
        foreach ([ActionRefusal::said('Hold refused'), ActionRefusal::fromThrowable(new DatabaseException('disk full'))] as $refusal) {
            $done = UserLegalHoldForgetDoneSignalData::refused($request, $refusal);
            for ($hop = 0; $hop < 2; ++$hop) {
                $done = UserLegalHoldForgetDoneSignalData::fromArray(self::overTheWire($done->toArray()));
                self::assertSame($request->toArray(), $done->request->toArray());
                self::assertSame($refusal->reason, $done->error);
                self::assertSame($refusal->errorType, $done->errorType);
                self::assertSame($refusal->errorDetail, $done->errorDetail);
            }
        }
    }

    /** @return iterable<string, array{int}> Invalid person ids */
    public static function invalidUserIds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidUserIds')]
    public function testRequestRefusesANonpositivePerson(int $userId): void
    {
        $this->expectException(InvalidFormatException::class);
        new UserLegalHoldForgetSignalData($userId, 'terms', 'first', HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET_DONE, 'corr-1');
    }

    /** @return iterable<string, array{string}> Required string fields */
    public static function requiredStrings(): iterable
    {
        yield 'document' => [UserLegalHoldForgetSignalData::document];
        yield 'revision' => [UserLegalHoldForgetSignalData::revisionId];
        yield 'reply' => [UserLegalHoldForgetSignalData::replySignal];
        yield 'correlation' => [UserLegalHoldForgetSignalData::correlationId];
    }

    #[DataProvider('requiredStrings')]
    public function testRequestRequiresEachStringField(string $field): void
    {
        $payload = (new UserLegalHoldForgetSignalData(
            7, 'terms', 'first', HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET_DONE, 'corr-1',
        ))->toArray();
        unset($payload[$field]);
        $this->expectException(InvalidFormatException::class);
        UserLegalHoldForgetSignalData::fromArray($payload);
    }

    /**
     * @param array<string, mixed> $payload Payload before JSON transport
     * @return array<string, mixed> Decoded payload
     */
    private static function overTheWire(array $payload): array
    {
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}

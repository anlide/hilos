<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Core\Action\ActionRefusal;
use Hilos\Database\DatabaseException;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictSignalData;
use PHPUnit\Framework\TestCase;

final class UserSecondFactorTrustRestrictDoneSignalDataTest extends TestCase
{
    public function testSuccessfulAnswerCarriesTheWholeRequestThroughTwoHops(): void
    {
        $request = self::request();
        $done = UserSecondFactorTrustRestrictDoneSignalData::ok($request);
        $expected = ['request' => $request->toArray(), 'error' => null, 'errorType' => null, 'errorDetail' => null];
        for ($hop = 0; $hop < 2; ++$hop) {
            $done = UserSecondFactorTrustRestrictDoneSignalData::fromArray(self::overTheWire($done->toArray()));
            self::assertSame($expected, $done->toArray());
        }
    }

    public function testRefusedAnswerKeepsRequestReasonAndInternalDetailThroughTwoHops(): void
    {
        $request = self::request();
        $refusals = [ActionRefusal::said('Trust restriction 19 is not saved'), ActionRefusal::fromThrowable(new DatabaseException('disk full'))];
        foreach ($refusals as $refusal) {
            $done = UserSecondFactorTrustRestrictDoneSignalData::refused($request, $refusal);
            for ($hop = 0; $hop < 2; ++$hop) {
                $done = UserSecondFactorTrustRestrictDoneSignalData::fromArray(self::overTheWire($done->toArray()));
                self::assertSame($request->toArray(), $done->request->toArray());
                self::assertSame($refusal->reason, $done->error);
                self::assertSame($refusal->errorType, $done->errorType);
                self::assertSame($refusal->errorDetail, $done->errorDetail);
            }
        }
    }

    /** @return UserSecondFactorTrustRestrictSignalData Restriction ask as a sender sends it */
    private static function request(): UserSecondFactorTrustRestrictSignalData
    {
        return new UserSecondFactorTrustRestrictSignalData(7, 19, 'test_trust_restrict_done');
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

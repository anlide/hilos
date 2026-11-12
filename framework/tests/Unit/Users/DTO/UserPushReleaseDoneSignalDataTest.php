<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Database\DatabaseException;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Users\DTO\UserPushReleaseDoneSignalData;
use Hilos\Users\DTO\UserPushReleaseSignalData;
use PHPUnit\Framework\TestCase;

final class UserPushReleaseDoneSignalDataTest extends TestCase
{
    public function testSuccessfulAnswerCarriesTheWholeRequestThroughTwoHops(): void
    {
        $request = self::request();
        $done = UserPushReleaseDoneSignalData::ok($request);
        $expected = ['request' => $request->toArray(), 'error' => null, 'errorType' => null, 'errorDetail' => null];
        for ($hop = 0; $hop < 2; ++$hop) {
            $done = UserPushReleaseDoneSignalData::fromArray(self::overTheWire($done->toArray()));
            self::assertSame($expected, $done->toArray());
        }
    }

    public function testRefusedAnswerKeepsRequestReasonAndInternalDetailThroughTwoHops(): void
    {
        $request = self::request();
        foreach ([ActionRefusal::said('Release refused'), ActionRefusal::fromThrowable(new DatabaseException('disk full'))] as $refusal) {
            $done = UserPushReleaseDoneSignalData::refused($request, $refusal);
            for ($hop = 0; $hop < 2; ++$hop) {
                $done = UserPushReleaseDoneSignalData::fromArray(self::overTheWire($done->toArray()));
                self::assertSame($request->toArray(), $done->request->toArray());
                self::assertSame($refusal->reason, $done->error);
                self::assertSame($refusal->errorType, $done->errorType);
                self::assertSame($refusal->errorDetail, $done->errorDetail);
            }
        }
    }

    /** @return UserPushReleaseSignalData Release ask with every optional field present */
    private static function request(): UserPushReleaseSignalData
    {
        return new UserPushReleaseSignalData(
            7, 19, 'https://push.example/device', 8, 'public-key', 'auth-secret', 'Firefox',
            HilosSignalConstants::HILOS_USER_PUSH_RELEASE_DONE,
            'accept-8', 'request-8', PushSubscriptionAction::SUBSCRIBE, null,
        );
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

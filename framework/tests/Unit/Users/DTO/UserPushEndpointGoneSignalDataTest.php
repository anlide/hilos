<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\DTO\UserPushEndpointGoneSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserPushEndpointGoneSignalDataTest extends TestCase
{
    /** @return iterable<string, array{int}> Captured revisions */
    public static function revisions(): iterable
    {
        yield 'initial revision' => [1];
        yield 'advanced revision' => [42];
    }

    /** @param int $revision Captured revision carried through the wire */
    #[DataProvider('revisions')]
    public function testReportSurvivesTwoWireHops(int $revision): void
    {
        $report = new UserPushEndpointGoneSignalData(7, 19, $revision);
        $wire = ['userId' => 7, 'subscriptionId' => 19, 'revision' => $revision];

        self::assertSame($wire, $report->toArray());
        for ($hop = 0; $hop < 2; ++$hop) {
            $report = UserPushEndpointGoneSignalData::fromArray(json_decode(
                json_encode($report->toArray(), JSON_THROW_ON_ERROR),
                true,
                flags: JSON_THROW_ON_ERROR,
            ));
            self::assertSame($wire, $report->toArray());
        }
    }

    /** @return iterable<string, array{int, int, int}> Invalid person, subscription ids and revision */
    public static function nonpositiveValues(): iterable
    {
        yield 'zero person' => [0, 19, 1];
        yield 'negative person' => [-1, 19, 1];
        yield 'zero subscription' => [7, 0, 1];
        yield 'negative subscription' => [7, -1, 1];
        yield 'zero revision' => [7, 19, 0];
        yield 'negative revision' => [7, 19, -1];
    }

    /**
     * @param int $userId Reported person id
     * @param int $subscriptionId Reported device id
     * @param int $revision Reported revision
     */
    #[DataProvider('nonpositiveValues')]
    public function testReportRefusesAnyNonpositiveValue(int $userId, int $subscriptionId, int $revision): void
    {
        $this->expectException(InvalidFormatException::class);
        UserPushEndpointGoneSignalData::fromArray([
            'userId' => $userId,
            'subscriptionId' => $subscriptionId,
            'revision' => $revision,
        ]);
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid revision payloads */
    public static function invalidRevisionPayloads(): iterable
    {
        yield 'missing revision' => [['userId' => 7, 'subscriptionId' => 19]];
        yield 'null revision' => [['userId' => 7, 'subscriptionId' => 19, 'revision' => null]];
        yield 'string revision' => [['userId' => 7, 'subscriptionId' => 19, 'revision' => '1']];
        yield 'legacy lastSeenAt payload' => [['userId' => 7, 'subscriptionId' => 19, 'lastSeenAt' => 1767225600123]];
    }

    /** @param array<string, mixed> $payload Invalid payload */
    #[DataProvider('invalidRevisionPayloads')]
    public function testReportRefusesMissingOrInvalidRevision(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        UserPushEndpointGoneSignalData::fromArray($payload);
    }
}

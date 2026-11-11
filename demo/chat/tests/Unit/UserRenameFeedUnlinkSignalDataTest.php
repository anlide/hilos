<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Router\DTO\UserRenameFeedUnlinkSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserRenameFeedUnlinkSignalDataTest extends TestCase
{
    public function testHistoryClearCorrelationSurvivesTwoWireHops(): void
    {
        $frame = new UserRenameFeedUnlinkSignalData(7, 'clear-attempt');
        $wire = ['userId' => 7, 'clearId' => 'clear-attempt'];
        self::assertSame($wire, $frame->toArray());
        for ($hop = 0; $hop < 2; ++$hop) {
            $frame = UserRenameFeedUnlinkSignalData::fromJson($frame->toJson());
            self::assertSame($wire, $frame->toArray());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid transport fields */
    public static function invalidPayloads(): iterable
    {
        $valid = ['userId' => 7, 'clearId' => 'clear-attempt'];
        foreach ([0, -1, '7', null] as $invalid) {
            yield 'userId:' . json_encode($invalid) => [array_replace($valid, ['userId' => $invalid])];
        }
        foreach (['', 7, null] as $invalid) {
            yield 'clearId:' . json_encode($invalid) => [array_replace($valid, ['clearId' => $invalid])];
        }
        foreach (['userId', 'clearId'] as $field) {
            $missing = $valid;
            unset($missing[$field]);
            yield $field . ':missing' => [$missing];
        }
    }

    /** @param array<string, mixed> $wire Broken transport payload */
    #[DataProvider('invalidPayloads')]
    public function testRefusesAMissingMistypedOrInvalidField(array $wire): void
    {
        $this->expectException(InvalidFormatException::class);
        UserRenameFeedUnlinkSignalData::fromArray($wire);
    }
}

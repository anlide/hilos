<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Core\Router\DTO\UserRenameFeedUnlinkedSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserRenameFeedUnlinkedSignalDataTest extends TestCase
{
    public function testHistoryClearCorrelationSurvivesTwoWireHops(): void
    {
        $frame = new UserRenameFeedUnlinkedSignalData(7, 'clear-attempt', false);
        $wire = ['userId' => 7, 'clearId' => 'clear-attempt', 'unlinked' => false];
        self::assertSame($wire, $frame->toArray());
        for ($hop = 0; $hop < 2; ++$hop) {
            $frame = UserRenameFeedUnlinkedSignalData::fromJson($frame->toJson());
            self::assertSame($wire, $frame->toArray());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid transport fields */
    public static function invalidPayloads(): iterable
    {
        $valid = ['userId' => 7, 'clearId' => 'clear-attempt', 'unlinked' => false];
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
        foreach ([0, 1, 'true', null] as $invalid) {
            yield 'unlinked:' . json_encode($invalid) => [array_replace($valid, ['unlinked' => $invalid])];
        }
        $missingOutcome = $valid;
        unset($missingOutcome['unlinked']);
        yield 'unlinked:missing' => [$missingOutcome];
    }

    /** @param array<string, mixed> $wire Broken transport payload */
    #[DataProvider('invalidPayloads')]
    public function testRefusesAMissingMistypedOrInvalidField(array $wire): void
    {
        $this->expectException(InvalidFormatException::class);
        UserRenameFeedUnlinkedSignalData::fromArray($wire);
    }
}

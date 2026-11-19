<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\DTO\AccountMergeAcceptedReplyDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountMergeAcceptedReplyDTOTest extends TestCase
{
    public function testTheAcceptedOperationRoundTrips(): void
    {
        $reply = new AccountMergeAcceptedReplyDTO(31);
        self::assertSame(['operationId' => 31], $reply->toArray());
        self::assertSame(31, AccountMergeAcceptedReplyDTO::fromArray($reply->toArray())->operationId);
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid operation addresses */
    public static function invalidReplies(): iterable
    {
        yield 'missing' => [[]];
        yield 'zero' => [['operationId' => 0]];
        yield 'negative' => [['operationId' => -1]];
        yield 'string' => [['operationId' => '31']];
        yield 'null' => [['operationId' => null]];
        yield 'float' => [['operationId' => 31.0]];
        yield 'boolean' => [['operationId' => true]];
    }

    /** @param array<string, mixed> $wire Invalid reply payload */
    #[DataProvider('invalidReplies')]
    public function testOnlyAPositiveIntegerNamesAnAcceptedOperation(array $wire): void
    {
        $this->expectException(InvalidFormatException::class);
        AccountMergeAcceptedReplyDTO::fromArray($wire);
    }
}

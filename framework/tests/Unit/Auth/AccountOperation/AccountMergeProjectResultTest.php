<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountMergeProjectResult;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The result a project owner's merge part records, written literally and read back. */
final class AccountMergeProjectResultTest extends TestCase
{
    /** Rows moved per family are written as exactly one literal object. */
    public function testPayloadIsTheLiteralRowsObject(): void
    {
        $payload = AccountMergeProjectResult::payloadOf(['messages' => 3, 'events' => 0]);

        self::assertSame('{"rows":{"messages":3,"events":0}}', $payload->toJson());
    }

    /** Nothing moved still writes rows as a JSON object, and reads back as nothing moved. */
    public function testEmptyMoveKeepsRowsAnObject(): void
    {
        $payload = AccountMergeProjectResult::payloadOf([]);

        self::assertSame('{"rows":{}}', $payload->toJson());
        self::assertSame([], AccountMergeProjectResult::rowsMovedOf($payload));
    }

    /** A written payload reads back to the same rows per family. */
    public function testSavedPayloadReadsBackToTheSameRows(): void
    {
        self::assertSame(
            ['messages' => 3, 'events' => 1],
            AccountMergeProjectResult::rowsMovedOf(AccountMergeProjectResult::payloadOf(['messages' => 3, 'events' => 1])),
        );
        self::assertSame(['notes' => 2], AccountMergeProjectResult::rowsMovedOf(AccountOperationResultPayload::fromJson('{"rows":{"notes":2}}')));
    }

    /** @return array<string, array{array<array-key, mixed>}> Owner answers that do not count rows per family */
    public static function wrongAnswers(): array
    {
        return [
            'count as a string' => [['messages' => '3']],
            'fractional count' => [['messages' => 1.5]],
            'negative count' => [['messages' => -1]],
            'family as a number' => [[7 => 3]],
            'list of counts' => [[3, 1]],
        ];
    }

    /** @param array<array-key, mixed> $rowsMoved Owner answer of a wrong form */
    #[DataProvider('wrongAnswers')]
    public function testWrongAnswerIsRefused(array $rowsMoved): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('An account merge part must count the rows it moved per family in non-negative integers.');

        AccountMergeProjectResult::payloadOf($rowsMoved);
    }

    /** @return array<string, array{string}> Saved payloads that are not a project merge result */
    public static function wrongForms(): array
    {
        return [
            'no rows' => ['{}'],
            'extra key' => ['{"rows":{},"files":[]}'],
            'rows as a list' => ['{"rows":[]}'],
            'null rows' => ['{"rows":null}'],
            'count as a string' => ['{"rows":{"messages":"3"}}'],
            'fractional count' => ['{"rows":{"messages":1.5}}'],
        ];
    }

    /** @param string $json Saved payload of a wrong form */
    #[DataProvider('wrongForms')]
    public function testWrongFormIsRefused(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('An account merge result must be {"rows":{…}}.');

        AccountMergeProjectResult::rowsMovedOf(AccountOperationResultPayload::fromJson($json));
    }
}

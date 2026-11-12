<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountErasureProjectResult;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\AccountErasure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The result a project owner's erasure part records, written literally and read back. */
final class AccountErasureProjectResultTest extends TestCase
{
    /** Rows per family and the registry files are written as exactly one literal object. */
    public function testPayloadIsTheLiteralRowsAndFilesObject(): void
    {
        $payload = AccountErasureProjectResult::payloadOf(new AccountErasure(['messages' => 3, 'events' => 1], [42, 7]));

        self::assertSame('{"rows":{"messages":3,"events":1},"files":[42,7]}', $payload->toJson());
    }

    /** Nothing erased still writes rows as a JSON object, and reads back as nothing erased. */
    public function testEmptyErasureKeepsRowsAnObject(): void
    {
        $payload = AccountErasureProjectResult::payloadOf(new AccountErasure([], []));

        self::assertSame('{"rows":{},"files":[]}', $payload->toJson());
        $erasure = AccountErasureProjectResult::erasureOf($payload);
        self::assertSame([], $erasure->rowsErased);
        self::assertSame([], $erasure->fileIds);
    }

    /** A written payload reads back to the same answer, whatever the key order of the saved object. */
    public function testSavedPayloadReadsBackToTheSameErasure(): void
    {
        $erasure = AccountErasureProjectResult::erasureOf(
            AccountErasureProjectResult::payloadOf(new AccountErasure(['messages' => 3, 'events' => 1], [42, 7])),
        );
        self::assertSame(['messages' => 3, 'events' => 1], $erasure->rowsErased);
        self::assertSame([42, 7], $erasure->fileIds);

        $reordered = AccountErasureProjectResult::erasureOf(AccountOperationResultPayload::fromJson('{"files":[5],"rows":{"notes":2}}'));
        self::assertSame(['notes' => 2], $reordered->rowsErased);
        self::assertSame([5], $reordered->fileIds);
    }

    /** @return array<string, array{string}> Saved payloads that are not a project erasure result */
    public static function wrongForms(): array
    {
        return [
            'no rows' => ['{"files":[]}'],
            'no files' => ['{"rows":{}}'],
            'rows as a list' => ['{"rows":[],"files":[]}'],
            'files as an object' => ['{"rows":{},"files":{}}'],
            'null rows' => ['{"rows":null,"files":[]}'],
            'count as a string' => ['{"rows":{"messages":"3"},"files":[]}'],
            'fractional count' => ['{"rows":{"messages":1.5},"files":[]}'],
            'file id as a string' => ['{"rows":{},"files":["42"]}'],
            'extra key' => ['{"rows":{},"files":[],"owner":"chat"}'],
        ];
    }

    /** @param string $json Saved payload of a wrong form */
    #[DataProvider('wrongForms')]
    public function testWrongFormIsRefused(string $json): void
    {
        $this->expectException(InvalidFormatException::class);

        AccountErasureProjectResult::erasureOf(AccountOperationResultPayload::fromJson($json));
    }
}

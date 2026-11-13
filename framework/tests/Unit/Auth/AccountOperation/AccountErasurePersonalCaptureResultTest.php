<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccountOperation;

use Hilos\Auth\AccountOperation\AccountErasurePersonalCaptureResult;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Core\Exception\InvalidFormatException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The capture the person's agent records in an erasure, written literally and read back (HIL-1940). */
final class AccountErasurePersonalCaptureResultTest extends TestCase
{
    /** Repeated addresses are written once, in ascending string order, and the empty project stays an object. */
    public function testAddressesAreUniqueAndSortedAndAnEmptyProjectIsAnObject(): void
    {
        $payload = AccountErasurePersonalCaptureResult::payloadOf(['b@example.test', '+15550100', 'a@example.test', 'b@example.test'], []);

        self::assertSame('{"addresses":["+15550100","a@example.test","b@example.test"],"project":{}}', $payload->toJson());
        $capture = AccountErasurePersonalCaptureResult::fromPayload($payload);
        self::assertSame(['+15550100', 'a@example.test', 'b@example.test'], $capture->addresses);
        self::assertSame([], $capture->project);
    }

    /** The project's keys are kept as the project gave them and read back as arrays. */
    public function testTheProjectPartIsKeptAndReadBack(): void
    {
        $payload = AccountErasurePersonalCaptureResult::payloadOf([], ['renameEvents' => [3, 7, 12]]);

        self::assertSame('{"addresses":[],"project":{"renameEvents":[3,7,12]}}', $payload->toJson());
        $capture = AccountErasurePersonalCaptureResult::fromPayload($payload);
        self::assertSame([], $capture->addresses);
        self::assertSame(['renameEvents' => [3, 7, 12]], $capture->project);
    }

    /** An address that is not valid UTF-8 cannot be written down. */
    public function testAnAddressThatIsNotUtf8IsRefused(): void
    {
        $this->expectException(JsonException::class);

        AccountErasurePersonalCaptureResult::payloadOf(["\xB1\x31"], []);
    }

    /** @return array<string, array{string}> Saved payloads that are not a personal capture */
    public static function wrongForms(): array
    {
        return [
            'empty' => ['{}'],
            'keys swapped' => ['{"project":{},"addresses":[]}'],
            'extra key' => ['{"addresses":[],"project":{},"rows":{}}'],
            'no project' => ['{"addresses":[]}'],
            'project a list' => ['{"addresses":[],"project":[]}'],
            'addresses an object' => ['{"addresses":{"0":"a@example.test"},"project":{}}'],
            'address a number' => ['{"addresses":["a@example.test",7],"project":{}}'],
            'address null' => ['{"addresses":[null],"project":{}}'],
        ];
    }

    /** @param string $json Saved payload of a wrong form */
    #[DataProvider('wrongForms')]
    public function testWrongFormIsRefused(string $json): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('An account erasure capture must be {"addresses":[…],"project":{…}}.');

        AccountErasurePersonalCaptureResult::fromPayload(AccountOperationResultPayload::fromJson($json));
    }
}

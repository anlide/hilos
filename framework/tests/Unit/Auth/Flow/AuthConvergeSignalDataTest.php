<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Flow;

use Hilos\Auth\Flow\DTO\AuthConvergeSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthConvergeSignalDataTest extends TestCase
{
    public function testRoundtripKeepsTheServerWordsAndTarget(): void
    {
        $frame = new AuthConvergeSignalData(
            'waiting-tab',
            'ada@example.com',
            'identifier',
            'login',
            'A notice chosen by the server, including Unicode: déjà vu.',
            'identifier_taken',
        );

        self::assertSame('waiting-tab', $frame->getAcceptKey());
        self::assertSame([
            'acceptKey' => 'waiting-tab',
            'identifier' => 'ada@example.com',
            'step' => 'identifier',
            'intent' => 'login',
            'message' => 'A notice chosen by the server, including Unicode: déjà vu.',
            'code' => 'identifier_taken',
        ], $frame->toArray());
        self::assertEquals($frame, AuthConvergeSignalData::fromArray($frame->toArray()));
    }

    public function testQuietMovementWritesNullWordsAndStillOmitsCode(): void
    {
        $frame = new AuthConvergeSignalData('waiting-tab', 'ada@example.com', 'done', 'register', null);

        self::assertSame([
            'acceptKey' => 'waiting-tab',
            'identifier' => 'ada@example.com',
            'step' => 'done',
            'intent' => 'register',
            'message' => null,
        ], $frame->toArray());
        self::assertEquals($frame, AuthConvergeSignalData::fromArray($frame->toArray()));
    }

    /**
     * @param array<string, mixed> $messageField Missing or mistyped required message
     */
    #[DataProvider('malformedMessages')]
    public function testMalformedMessageIsRefused(array $messageField): void
    {
        $this->expectException(InvalidFormatException::class);

        AuthConvergeSignalData::fromArray([
            'acceptKey' => 'waiting-tab',
            'identifier' => 'ada@example.com',
            'step' => 'identifier',
            'intent' => 'login',
            'code' => 'identifier_taken',
        ] + $messageField);
    }

    /** @return array<string, array{array<string, mixed>}> Invalid message fields */
    public static function malformedMessages(): array
    {
        return [
            'missing' => [[]],
            'integer' => [['message' => 42]],
            'boolean' => [['message' => false]],
            'array' => [['message' => []]],
        ];
    }
}

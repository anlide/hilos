<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\HilosProfilePhotoCheck;
use PHPUnit\Framework\TestCase;

/** The pending-check row sent between workers keeps its connection and upload identity. */
final class HilosProfilePhotoCheckTest extends TestCase
{
    public function testRoundTripsTheCompleteCheck(): void
    {
        $check = HilosProfilePhotoCheck::create('accept-key', 7, 'upload-1', 123456);
        $restored = HilosProfilePhotoCheck::fromRow($check->toArray());

        self::assertSame('accept-key', $restored->getId());
        self::assertSame($check->toArray(), $restored->toArray());
    }

    public function testRefusesAnIncompleteSyncRow(): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosProfilePhotoCheck::fromRow([
            HilosProfilePhotoCheck::acceptKey => 'accept-key',
            HilosProfilePhotoCheck::userId => 7,
            HilosProfilePhotoCheck::startedAt => 123456,
        ]);
    }
}

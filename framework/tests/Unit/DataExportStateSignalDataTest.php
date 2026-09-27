<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\DataExport\DTO\DataExportForgetUserSignalData;
use Hilos\DataExport\DataExportTime;
use Hilos\DataExport\DTO\DataExportStateSignalData;
use PHPUnit\Framework\TestCase;

final class DataExportStateSignalDataTest extends TestCase
{
    /** The worker-to-daemon roundtrip retains optional state fields and absence. */
    public function testRoundtrip(): void
    {
        foreach ([null, [
            'state' => 'ready',
            'requestedAt' => 1000,
            'finishedAt' => 2000,
            'expiresAt' => 3000,
            'sizeBytes' => 456,
        ], [
            'state' => 'preparing',
            'requestedAt' => 1000,
            'finishedAt' => null,
            'expiresAt' => null,
            'sizeBytes' => null,
        ]] as $node) {
            $dto = new DataExportStateSignalData($node);
            self::assertSame($dto->toArray(), DataExportStateSignalData::fromArray($dto->toArray())->toArray());
        }
    }

    /** The proof used by a refused sign-in and erasure identity survive the process boundary. */
    public function testAuthProofAndForgetUserRoundtrips(): void
    {
        $grant = new AuthSessionGrantSignalData('session', 7, 'socket', provenBy: 'password');
        self::assertSame('password', AuthSessionGrantSignalData::fromArray($grant->toArray())->provenBy);
        $old = $grant->toArray();
        unset($old['provenBy']);
        self::assertNull(AuthSessionGrantSignalData::fromArray($old)->provenBy);
        $forget = new DataExportForgetUserSignalData(7);
        self::assertSame(7, DataExportForgetUserSignalData::fromArray($forget->toArray())->userId);
    }

    /** A request has no meaning without its ordering date. */
    public function testMissingRequestTimeIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        DataExportStateSignalData::fromArray(['dataExport' => ['state' => 'preparing']]);
    }

    /** Archive dates retain nullable values and normalize a stored timestamp to UTC. */
    public function testArchiveDates(): void
    {
        self::assertNull(DataExportTime::iso(null));
        self::assertSame('2026-09-27T10:15:30Z', DataExportTime::iso('2026-09-27 10:15:30 UTC'));
    }
}

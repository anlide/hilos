<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\WebAuthn\Base64Url;
use Hilos\Cluster\Peer\DTO\PeerDbSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\DbSyncAdmissionData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use PHPUnit\Framework\TestCase;

final class DbSyncAdmissionDataTest extends TestCase
{
    public function testAllThreeFramesPreserveStampsThroughEveryTransportBoundary(): void
    {
        $snapshot = $this->passkey();
        $admission = new DbSyncAdmissionData(10, 11, 12, 13, $snapshot);
        foreach ([
            [new DbSyncCreatedSignalData(HilosDbContext::passkeyCredentials, '7', $snapshot, admission: $admission),
                WorkerDbSyncCreatedMessageDTO::class, SignalTypeConstants::DB_SYNC_CREATED, SignalConstants::DB_SYNC_CREATED],
            [new DbSyncUpdatedSignalData(HilosDbContext::passkeyCredentials, '7', ['user_handle' => $snapshot['user_handle']],
                previous: ['user_handle' => "\xff\x00old"], admission: $admission),
                WorkerDbSyncUpdatedMessageDTO::class, SignalTypeConstants::DB_SYNC_UPDATED, SignalConstants::DB_SYNC_UPDATED],
            [new DbSyncDeletedSignalData(HilosDbContext::passkeyCredentials, '7', $snapshot,
                admission: new DbSyncAdmissionData(10, 11, 12, 14, null)),
                WorkerDbSyncDeletedMessageDTO::class, SignalTypeConstants::DB_SYNC_DELETED, SignalConstants::DB_SYNC_DELETED],
        ] as [$frame, $workerClass, $signalType, $signalName]) {
            $frame = $frame->withEmitter('source-process');
            $wire = $frame->toArray();
            $this->assertSame(Base64Url::encode($snapshot['user_handle']), $wire['row']['user_handle']);
            $this->assertSame($frame->row, $frame::fromJson($frame->toJson())->row);
            $signal = new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType($signalType), new SignalName($signalName), $frame);
            $peer = PeerDTO::fromWire((new PeerDbSyncDTO('source-node', $signalType, $signal))->toJson());
            $this->assertInstanceOf(PeerDbSyncDTO::class, $peer);
            $worker = new $workerClass($peer->signal->data, 'source-node');
            $restored = $workerClass::fromJson($worker->toJson())->signalData;
            $this->assertSame($frame->toArray(), $restored->toArray());
            $this->assertSame($frame->admission->snapshot, $restored->admission->snapshot);
            $this->assertSame('source-process', $restored->emitter);
            if ($restored instanceof DbSyncUpdatedSignalData) {
                $this->assertSame("\xff\x00old", $restored->previous['user_handle']);
            }
        }
    }

    public function testUnmappedSnapshotsPreserveSignedMillisecondsAndTotpSteps(): void
    {
        foreach ([
            [HilosDbContext::identities, ['id' => 7, 'user_id' => 12, 'type' => 'email', 'identifier' => 'member@example.test',
                'provider' => null, 'verified' => true, 'password_changed_at' => -1, 'provider_name' => null, 'created_at' => -2, 'updated_at' => 0]],
            [HilosDbContext::secondFactors, ['id' => 7, 'user_id' => 12, 'label' => 'Key', 'last_used_step' => 17,
                'confirmed_at' => null, 'last_used_at' => -1, 'created_at' => -2]],
            [HilosDbContext::secondFactorBackupCodes, ['id' => 7, 'user_id' => 12, 'used_at' => -1, 'created_at' => -2]],
        ] as [$collectionKey, $snapshot]) {
            $frame = new DbSyncUpdatedSignalData($collectionKey, '7', [], admission: new DbSyncAdmissionData(10, 11, 12, 13, $snapshot));
            $restored = DbSyncUpdatedSignalData::fromJson($frame->toJson());
            $this->assertSame([], $restored->row);
            $this->assertSame($snapshot, $restored->admission->snapshot);
        }
    }

    public function testLegacyFramesKeepTheirRowsAndSerializeNullAdmission(): void
    {
        foreach ([DbSyncCreatedSignalData::class, DbSyncUpdatedSignalData::class, DbSyncDeletedSignalData::class] as $class) {
            $frame = new $class('events', '7', ['name' => 'event']);
            $wire = $frame->toArray();
            $this->assertNull($wire['admission']);
            unset($wire['admission']);
            $restored = $class::fromArray($wire);
            $this->assertNull($restored->admission);
            $this->assertSame($frame->row, $restored->row);
        }
    }

    public function testRejectsMalformedAdmissionCoordinatesAndSnapshotFields(): void
    {
        $frame = new DbSyncCreatedSignalData(HilosDbContext::passkeyCredentials, '7', $this->passkey(),
            admission: new DbSyncAdmissionData(10, 11, 12, 13, $this->passkey()));
        $valid = $frame->toArray();
        $invalid = [];
        foreach (['operationId', 'assignmentId', 'ownerUserId', 'revision'] as $key) {
            foreach ([0, -1, '13', 1.5, null] as $value) {
                $wire = $valid;
                $wire['admission'][$key] = $value;
                $invalid[] = $wire;
            }
        }
        foreach (['secret', 'hash', 'code'] as $key) {
            $wire = $valid;
            $wire['admission']['snapshot'][$key] = 'do-not-log';
            $invalid[] = $wire;
            $wire = $valid;
            $wire['row'][$key] = 'do-not-log';
            $invalid[] = $wire;
        }
        foreach (['id' => 8, 'user_id' => 14, 'public_key' => 'other-key', 'identity_id' => 0] as $key => $value) {
            $wire = $valid;
            $wire['row'][$key] = $value;
            $invalid[] = $wire;
        }
        foreach (['id', 'created_at', 'user_handle'] as $key) {
            $wire = $valid;
            unset($wire['admission']['snapshot'][$key]);
            $invalid[] = $wire;
        }
        foreach ([null, [], false, 'stamp'] as $value) {
            $wire = $valid;
            $wire['admission']['snapshot'] = $value;
            $invalid[] = $wire;
        }
        foreach ([[], false, 'stamp'] as $value) {
            $wire = $valid;
            $wire['admission'] = $value;
            $invalid[] = $wire;
        }
        $wire = $valid;
        $wire['admission']['snapshot']['user_id'] = 14;
        $invalid[] = $wire;
        $wire = $valid;
        $wire['admission']['snapshot']['sign_count'] = '1';
        $invalid[] = $wire;
        foreach ($invalid as $wire) {
            try {
                DbSyncCreatedSignalData::fromArray($wire);
                $this->fail('Malformed admission must be refused.');
            } catch (InvalidFormatException $failure) {
                $this->assertStringNotContainsString('do-not-log', $failure->getMessage());
            }
        }
    }

    public function testDeleteBoundaryAndCanonicalBinaryCodecAreStrict(): void
    {
        $frame = new DbSyncUpdatedSignalData(HilosDbContext::passkeyCredentials, '7', ['user_handle' => "\xff"],
            previous: ['user_handle' => "\xfe"], admission: new DbSyncAdmissionData(10, 11, 12, 13, $this->passkey()));
        foreach (['row', 'previous', 'snapshot'] as $place) {
            foreach (['_w==', '/w', '_x', '*', 13, null] as $value) {
                $wire = $frame->toArray();
                if ($place === 'snapshot') {
                    $wire['admission']['snapshot']['user_handle'] = $value;
                } else {
                    $wire[$place]['user_handle'] = $value;
                }
                try {
                    DbSyncUpdatedSignalData::fromArray($wire);
                    $this->fail('A binary value must have a canonical representation.');
                } catch (InvalidFormatException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->expectException(InvalidFormatException::class);
        DbSyncDeletedSignalData::fromArray($frame->toArray());
    }

    /** @return array<string, mixed> Complete mapped passkey row with non-UTF8 handle bytes */
    private function passkey(): array
    {
        return ['id' => 7, 'identity_id' => 8, 'user_id' => 12, 'credential_id' => 'credential', 'public_key' => 'public',
            'algorithm' => -7, 'sign_count' => 1, 'transports' => null, 'aaguid' => null, 'user_handle' => "\x00\xff\xfehandle",
            'label' => 'Key', 'last_used_at' => -123, 'created_at' => -456];
    }
}

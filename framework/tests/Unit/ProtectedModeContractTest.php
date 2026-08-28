<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeReadySignalData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the protected mode contract data layer (HIL-267 slice 1).
 *
 * Pins the runtime row shape and the enable/ready/disable signal payloads that the leader
 * orchestration and the master welcome path (later slices) build on. The freeze behaviour
 * itself is cluster-wide and exercised at e2e in demo/cluster; here we lock the serialized
 * shape so the writer seam and the wire never drift from the approved contract-gate.
 */
final class ProtectedModeContractTest extends TestCase
{
    public function testRuntimeIsCreatedInactive(): void
    {
        $runtime = ProtectedModeRuntime::create();

        $this->assertSame(ProtectedModeRuntime::ID, $runtime->getId());
        $this->assertSame(ProtectedModeRuntime::PHASE_INACTIVE, $runtime->phase);
        $this->assertNull($runtime->operation);
        $this->assertNull($runtime->initiatorAcceptKey);
        $this->assertNull($runtime->initiatorAgentIndex);
        $this->assertNull($runtime->startedAt);
        $this->assertSame([], $runtime->passHashes);
        $this->assertSame([], $runtime->admittedAcceptKeys);
        $this->assertSame(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::getRtCollectionKey());
    }

    public function testRuntimeRoundTripsThroughRow(): void
    {
        $row = [
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::operation => 'restore',
            ProtectedModeRuntime::initiatorAcceptKey => 'accept-9',
            ProtectedModeRuntime::initiatorSessionTokenHash => 'session-hash-9',
            ProtectedModeRuntime::initiatorAgentType => 'backup',
            ProtectedModeRuntime::initiatorAgentIndex => 0,
            ProtectedModeRuntime::initiatorNodeId => 'node-a',
            ProtectedModeRuntime::startedAt => 1_700_000_000,
            ProtectedModeRuntime::activatedAt => 1_700_000_005,
            ProtectedModeRuntime::progressAt => null,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedAcceptKeys => [],
        ];

        $runtime = ProtectedModeRuntime::fromRow($row);

        $this->assertSame($row, $runtime->toArray());
        $this->assertSame(0, $runtime->initiatorAgentIndex);
        $this->assertSame(1_700_000_000, $runtime->startedAt);
    }

    public function testVerifyingRuntimeRoundTripsBothLists(): void
    {
        $row = [
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::operation => 'restore',
            ProtectedModeRuntime::initiatorAcceptKey => 'accept-initiator',
            ProtectedModeRuntime::initiatorSessionTokenHash => 'session-hash-initiator',
            ProtectedModeRuntime::initiatorAgentType => 'backup',
            ProtectedModeRuntime::initiatorAgentIndex => null,
            ProtectedModeRuntime::initiatorNodeId => 'node-a',
            ProtectedModeRuntime::startedAt => 1_700_000_000,
            ProtectedModeRuntime::activatedAt => 1_700_000_005,
            ProtectedModeRuntime::progressAt => 1_700_000_030,
            ProtectedModeRuntime::passHashes => ['hash-a', 'hash-b'],
            ProtectedModeRuntime::admittedAcceptKeys => ['accept-verifier'],
        ];

        $runtime = ProtectedModeRuntime::fromRow($row);

        $this->assertSame($row, $runtime->toArray());
    }

    public function testRuntimeRefusesARowThatCarriesNoLists(): void
    {
        // Both lists are consulted by value and neither is nullable, so a row that carries
        // neither is a frame that lost them rather than a node with nothing to list: a node
        // with nothing to list writes two empty arrays.
        $this->expectException(InvalidFormatException::class);

        ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
        ]);
    }

    public function testRuntimeApplyDiffCarriesBothLists(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedAcceptKeys => [],
        ]);

        $runtime->applyDiff([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::passHashes => ['hash-a'],
            ProtectedModeRuntime::admittedAcceptKeys => ['accept-verifier'],
        ]);

        $this->assertSame(ProtectedModeRuntime::PHASE_VERIFYING, $runtime->phase);
        $this->assertSame(['hash-a'], $runtime->passHashes);
        $this->assertSame(['accept-verifier'], $runtime->admittedAcceptKeys);

        $runtime->applyDiff([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_DEACTIVATING,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedAcceptKeys => [],
        ]);

        $this->assertSame([], $runtime->passHashes);
        $this->assertSame([], $runtime->admittedAcceptKeys);
    }

    public function testRuntimeApplyDiffOverwritesOnlyPresentFields(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVATING,
            ProtectedModeRuntime::operation => 'restore',
            ProtectedModeRuntime::startedAt => 1_700_000_000,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedAcceptKeys => [],
        ]);

        $runtime->applyDiff([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::activatedAt => 1_700_000_005,
        ]);

        $this->assertSame(ProtectedModeRuntime::PHASE_ACTIVE, $runtime->phase);
        $this->assertSame('restore', $runtime->operation);
        $this->assertSame(1_700_000_000, $runtime->startedAt);
        $this->assertSame(1_700_000_005, $runtime->activatedAt);
    }

    public function testRuntimeApplyDiffClearsFieldToNull(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::operation => 'restore',
            ProtectedModeRuntime::initiatorAgentIndex => 2,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedAcceptKeys => [],
        ]);

        $runtime->applyDiff([
            ProtectedModeRuntime::operation => null,
            ProtectedModeRuntime::initiatorAgentIndex => null,
        ]);

        $this->assertNull($runtime->operation);
        $this->assertNull($runtime->initiatorAgentIndex);
    }

    public function testInactiveRuntimeLocksNobodyOut(): void
    {
        $runtime = ProtectedModeRuntime::create();

        $this->assertFalse($runtime->locksOut('accept-9', null));
        $this->assertFalse($runtime->locksOut(null, null));
    }

    public function testActiveRuntimeLocksOutEveryConnectionButTheInitiator(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::initiatorAcceptKey => 'accept-initiator',
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedAcceptKeys => [],
        ]);

        $this->assertFalse($runtime->locksOut('accept-initiator', null));
        $this->assertTrue($runtime->locksOut('accept-other', null));
        $this->assertTrue($runtime->locksOut(null, null));
    }

    public function testFollowerRuntimeLocksOutEveryoneWhileMerelyActivating(): void
    {
        // A follower quiesces to `activating` with no initiator key and never advances to
        // `active`, so the lockdown must engage on any non-inactive phase and lock every real
        // (server-minted, non-null) connection out.
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVATING,
            ProtectedModeRuntime::initiatorAcceptKey => null,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedAcceptKeys => [],
        ]);

        $this->assertTrue($runtime->locksOut('accept-9', null));
        $this->assertTrue($runtime->locksOut('accept-other', null));
    }

    public function testDeactivatingRuntimeStillLocksOutNonInitiator(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_DEACTIVATING,
            ProtectedModeRuntime::initiatorAcceptKey => 'accept-initiator',
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedAcceptKeys => [],
        ]);

        $this->assertFalse($runtime->locksOut('accept-initiator', null));
        $this->assertTrue($runtime->locksOut('accept-other', null));
    }

    public function testVerifyingRuntimeLetsTheInitiatorAndTheAdmittedThrough(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::initiatorAcceptKey => 'accept-initiator',
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedAcceptKeys => ['accept-verifier'],
        ]);

        $this->assertFalse($runtime->locksOut('accept-initiator', null));
        $this->assertFalse($runtime->locksOut('accept-verifier', null));
        $this->assertTrue($runtime->locksOut('accept-stranger', null));
        $this->assertTrue($runtime->locksOut(null, null));
    }

    public function testAdmitsAnswersOnlyForTheVerifyingPhase(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedAcceptKeys => ['accept-verifier'],
        ]);

        $this->assertTrue($runtime->admits('accept-verifier'));
        $this->assertFalse($runtime->admits('accept-stranger'));
        $this->assertFalse($runtime->admits(null));

        $runtime->applyDiff([ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_DEACTIVATING]);

        $this->assertFalse($runtime->admits('accept-verifier'));
    }

    public function testFrozenPhasesIgnoreAnAdmittedKeyEvenWhenTheRowStillCarriesIt(): void
    {
        // The actions clear both lists on the way out of the verification, so a frozen phase
        // holding an admitted key is a row that should not exist. It is asserted anyway: the
        // lockdown must be safe against a stale list, not merely against a tidy one.
        foreach (
            [
                ProtectedModeRuntime::PHASE_ACTIVATING,
                ProtectedModeRuntime::PHASE_ACTIVE,
                ProtectedModeRuntime::PHASE_DEACTIVATING,
            ] as $phase
        ) {
            $runtime = ProtectedModeRuntime::fromRow([
                ProtectedModeRuntime::phase => $phase,
                ProtectedModeRuntime::initiatorAcceptKey => 'accept-initiator',
                ProtectedModeRuntime::passHashes => [],
                ProtectedModeRuntime::admittedAcceptKeys => ['accept-verifier'],
            ]);

            $this->assertTrue($runtime->locksOut('accept-verifier', null), $phase);
            $this->assertFalse($runtime->admits('accept-verifier'), $phase);
        }
    }

    public function testEnableSignalDataRoundTrips(): void
    {
        $data = new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            initiatorNodeId: 'node-a',
        );

        $restored = ProtectedModeEnableSignalData::fromArray($data->toArray());

        $this->assertSame('restore', $restored->operation);
        $this->assertSame('accept-9', $restored->initiatorAcceptKey);
        $this->assertSame('backup', $restored->initiatorAgentType);
        $this->assertSame(0, $restored->initiatorAgentIndex);
        $this->assertSame('node-a', $restored->initiatorNodeId);
    }

    public function testEnableSignalDataKeepsNullAgentIndex(): void
    {
        $data = new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
            initiatorNodeId: 'node-a',
        );

        $restored = ProtectedModeEnableSignalData::fromArray($data->toArray());

        $this->assertNull($restored->initiatorAgentIndex);
    }

    public function testEnableSignalDataKeepsNullNodeId(): void
    {
        // A single-node installation has no node ids to name, and the freeze it asks for is
        // local, so the initiator sends null rather than inventing an identifier.
        $data = new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            initiatorNodeId: null,
        );

        $restored = ProtectedModeEnableSignalData::fromArray($data->toArray());

        $this->assertNull($restored->initiatorNodeId);
    }

    public function testReadySignalDataIsAnEmptyPayload(): void
    {
        $this->assertSame([], new ProtectedModeReadySignalData()->toArray());
        $this->assertInstanceOf(
            ProtectedModeReadySignalData::class,
            ProtectedModeReadySignalData::fromArray([]),
        );
    }

    public function testDisableSignalDataCarriesTheRequestingAgentIdentity(): void
    {
        $data = new ProtectedModeDisableSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 2,
        );

        $restored = ProtectedModeDisableSignalData::fromArray($data->toArray());

        $this->assertSame('backup', $restored->initiatorAgentType);
        $this->assertSame(2, $restored->initiatorAgentIndex);
    }

    public function testDisableSignalDataKeepsNullAgentIndex(): void
    {
        $data = new ProtectedModeDisableSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
        );

        $restored = ProtectedModeDisableSignalData::fromArray($data->toArray());

        $this->assertNull($restored->initiatorAgentIndex);
    }

    public function testProgressSignalDataCarriesTheReportingAgentIdentity(): void
    {
        $data = new ProtectedModeProgressSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 2,
        );

        $restored = ProtectedModeProgressSignalData::fromArray($data->toArray());

        $this->assertSame('backup', $restored->initiatorAgentType);
        $this->assertSame(2, $restored->initiatorAgentIndex);
    }

    public function testProgressSignalDataKeepsNullAgentIndex(): void
    {
        $data = new ProtectedModeProgressSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
        );

        $restored = ProtectedModeProgressSignalData::fromArray($data->toArray());

        $this->assertNull($restored->initiatorAgentIndex);
    }

    public function testProgressSignalDataCarriesNoTimestamp(): void
    {
        // The mark travels as a bare fact and the master stamps its own clock, so a node whose
        // clock is wrong cannot push another node's silence threshold around. Asserted on the
        // payload keys, because the whole guarantee is that there is nowhere to put one.
        $payload = new ProtectedModeProgressSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
        )->toArray();

        $this->assertSame(
            [
                ProtectedModeProgressSignalData::initiatorAgentType,
                ProtectedModeProgressSignalData::initiatorAgentIndex,
            ],
            array_keys($payload),
        );
    }

    public function testTheProgressMarkRoundTripsThroughTheRow(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::progressAt => 1_700_000_030,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedAcceptKeys => [],
        ]);

        $this->assertSame(1_700_000_030, $runtime->progressAt);
        $this->assertSame(1_700_000_030, $runtime->toArray()[ProtectedModeRuntime::progressAt]);

        $runtime->applyDiff([ProtectedModeRuntime::progressAt => 1_700_000_090]);

        $this->assertSame(1_700_000_090, $runtime->progressAt);
    }

    public function testARowThatPredatesTheProgressMarkReadsItAsNull(): void
    {
        // Null is the watchdog's "nothing has been reported yet", which it reads back to the
        // activation time - so a row minted by an older node must land there rather than on 0,
        // which would be an operation last seen in 1970 and instantly overdue.
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedAcceptKeys => [],
        ]);

        $this->assertNull($runtime->progressAt);
    }
}

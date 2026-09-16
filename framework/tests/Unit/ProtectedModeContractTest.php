<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\DTO\ProtectedModeCircleSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeReadySignalData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeCircleDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the protected mode contract data layer (HIL-267 slice 1).
 *
 * Pins the runtime row shape and the enable/ready/disable signal payloads that the leader
 * orchestration and the master welcome path (later slices) build on. The freeze behavior
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
        $this->assertSame([], $runtime->admittedSessionTokenHashes);
        $this->assertSame([], $runtime->circleSessionTokenHashes);
        $this->assertSame(0, $runtime->circleNamedCount);
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
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
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
            ProtectedModeRuntime::admittedSessionTokenHashes => ['session-hash-verifier'],
            ProtectedModeRuntime::circleSessionTokenHashes => ['session-hash-circle'],
            ProtectedModeRuntime::circleNamedCount => 3,
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
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]);

        $runtime->applyDiff([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::passHashes => ['hash-a'],
            ProtectedModeRuntime::admittedSessionTokenHashes => ['session-hash-verifier'],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]);

        $this->assertSame(ProtectedModeRuntime::PHASE_VERIFYING, $runtime->phase);
        $this->assertSame(['hash-a'], $runtime->passHashes);
        $this->assertSame(['session-hash-verifier'], $runtime->admittedSessionTokenHashes);

        $runtime->applyDiff([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_DEACTIVATING,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]);

        $this->assertSame([], $runtime->passHashes);
        $this->assertSame([], $runtime->admittedSessionTokenHashes);
    }

    public function testRuntimeApplyDiffCarriesThePhotographedCircle(): void
    {
        // The circle reaches a worker the same way the admissions do - as a diff of this row -
        // so a worker that never learned it would go on locking a named verifier out of the
        // window their own tab is already sitting in.
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]);

        $runtime->applyDiff([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::circleSessionTokenHashes => ['session-hash-circle'],
            ProtectedModeRuntime::circleNamedCount => 3,
        ]);

        $this->assertSame(['session-hash-circle'], $runtime->circleSessionTokenHashes);
        $this->assertSame(3, $runtime->circleNamedCount);
        $this->assertTrue($runtime->admitsCircle('session-hash-circle'));
        $this->assertFalse($runtime->admits('session-hash-circle'));
        $this->assertFalse($runtime->locksOut(null, 'session-hash-circle'));
    }

    public function testRuntimeApplyDiffOverwritesOnlyPresentFields(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVATING,
            ProtectedModeRuntime::operation => 'restore',
            ProtectedModeRuntime::startedAt => 1_700_000_000,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
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
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
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

    public function testActiveRuntimeLocksOutEveryConnectionIncludingTheInitiator(): void
    {
        // The recorded key does not buy a way in while the node is frozen: the agents behind
        // every page are down, so the socket that asked would find the same dead application as
        // anybody else and would lose the restore panel it is being shown instead.
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::initiatorAcceptKey => 'accept-initiator',
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]);

        $this->assertTrue($runtime->locksOut('accept-initiator', null));
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
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]);

        $this->assertTrue($runtime->locksOut('accept-9', null));
        $this->assertTrue($runtime->locksOut('accept-other', null));
    }

    public function testDeactivatingRuntimeStillLocksEverybodyOut(): void
    {
        // The way out of the freeze is not the window: the lift is what opens the node again, and
        // until it lands this phase holds the initiator on the same terms as everyone else.
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_DEACTIVATING,
            ProtectedModeRuntime::initiatorAcceptKey => 'accept-initiator',
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]);

        $this->assertTrue($runtime->locksOut('accept-initiator', null));
        $this->assertTrue($runtime->locksOut('accept-other', null));
    }

    public function testVerifyingRuntimeLetsTheInitiatorAndTheAdmittedThrough(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::initiatorAcceptKey => 'accept-initiator',
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => ['session-hash-verifier'],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]);

        $this->assertFalse($runtime->locksOut('accept-initiator', null));
        $this->assertFalse($runtime->locksOut('accept-verifier', 'session-hash-verifier'));
        $this->assertTrue($runtime->locksOut('accept-stranger', 'session-hash-stranger'));
        $this->assertTrue($runtime->locksOut(null, null));
    }

    public function testASecondTabOfTheAdmittedBrowserIsLetThroughWithoutItsOwnPass(): void
    {
        // The whole point of admitting a session rather than a socket: the verifier reads the
        // code once, and every tab it opens afterwards arrives with the same cookie and an
        // accept key the row has never seen.
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::initiatorAcceptKey => 'accept-initiator',
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => ['session-hash-verifier'],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]);

        $this->assertFalse($runtime->locksOut('accept-second-tab', 'session-hash-verifier'));
    }

    public function testAdmitsAnswersOnlyForTheVerifyingPhase(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => ['session-hash-verifier'],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]);

        $this->assertTrue($runtime->admits('session-hash-verifier'));
        $this->assertFalse($runtime->admits('session-hash-stranger'));
        $this->assertFalse($runtime->admits(null));

        $runtime->applyDiff([ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_DEACTIVATING]);

        $this->assertFalse($runtime->admits('session-hash-verifier'));
    }

    public function testAdmitsAnswersForEverySessionOnTheList(): void
    {
        // Several verifiers hold the same code at once, and the list is what the design keeps
        // it for: the second one entered must not be shadowed by the first.
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => ['session-hash-a', 'session-hash-b'],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]);

        $this->assertTrue($runtime->admits('session-hash-a'));
        $this->assertTrue($runtime->admits('session-hash-b'));
        $this->assertFalse($runtime->admits('session-hash-c'));
    }

    public function testFrozenPhasesIgnoreAnAdmittedSessionEvenWhenTheRowStillCarriesIt(): void
    {
        // The actions clear both lists on the way out of the verification, so a frozen phase
        // holding an admitted session is a row that should not exist. It is asserted anyway: the
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
                ProtectedModeRuntime::admittedSessionTokenHashes => ['session-hash-verifier'],
                ProtectedModeRuntime::circleSessionTokenHashes => [],
                ProtectedModeRuntime::circleNamedCount => 0,
            ]);

            $this->assertTrue($runtime->locksOut('accept-verifier', 'session-hash-verifier'), $phase);
            $this->assertFalse($runtime->admits('session-hash-verifier'), $phase);
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

    public function testCircleSignalDataRoundTripsBothHalvesOfThePhotograph(): void
    {
        $data = new ProtectedModeCircleSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 2,
            namedCount: 3,
            sessionTokenHashes: ['session-hash-a', 'session-hash-b'],
        );

        $restored = ProtectedModeCircleSignalData::fromArray($data->toArray());

        $this->assertSame('backup', $restored->initiatorAgentType);
        $this->assertSame(2, $restored->initiatorAgentIndex);
        $this->assertSame(3, $restored->namedCount);
        $this->assertSame(['session-hash-a', 'session-hash-b'], $restored->sessionTokenHashes);
    }

    public function testCircleSignalDataKeepsAnEmptyPhotographOfANamedCircle(): void
    {
        // The pair the count exists for: nobody named was online is not the same fact as nobody
        // was named, and the frame has to carry the difference the row cannot recover later.
        $data = new ProtectedModeCircleSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
            namedCount: 4,
            sessionTokenHashes: [],
        );

        $restored = ProtectedModeCircleSignalData::fromArray($data->toArray());

        $this->assertNull($restored->initiatorAgentIndex);
        $this->assertSame(4, $restored->namedCount);
        $this->assertSame([], $restored->sessionTokenHashes);
    }

    public function testCircleSignalDataRefusesAPayloadThatNamesNoHashList(): void
    {
        $this->expectException(InvalidFormatException::class);

        ProtectedModeCircleSignalData::fromArray([
            ProtectedModeCircleSignalData::initiatorAgentType => 'backup',
            ProtectedModeCircleSignalData::initiatorAgentIndex => null,
            ProtectedModeCircleSignalData::namedCount => 1,
        ]);
    }

    public function testTheCircleFrameRoundTripsThroughItsWorkerEnvelope(): void
    {
        // Through the registry rather than the class, because the registry is the half that can
        // be forgotten: a frame nobody registered decodes into an unknown-type refusal.

        $frame = new WorkerProtectedModeCircleDTO(new ProtectedModeCircleSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            namedCount: 2,
            sessionTokenHashes: ['session-hash-a'],
        ));

        $restored = WorkerDTO::factoryWorkerDTO(json_encode($frame->toArray(), JSON_THROW_ON_ERROR));

        $this->assertInstanceOf(WorkerProtectedModeCircleDTO::class, $restored);
        $this->assertSame(2, $restored->data->namedCount);
        $this->assertSame(['session-hash-a'], $restored->data->sessionTokenHashes);
    }

    public function testTheProgressMarkRoundTripsThroughTheRow(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::progressAt => 1_700_000_030,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
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
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]);

        $this->assertNull($runtime->progressAt);
    }
}

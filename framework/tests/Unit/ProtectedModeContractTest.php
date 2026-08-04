<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
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
        $this->assertSame(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::getRtCollectionKey());
    }

    public function testRuntimeRoundTripsThroughRow(): void
    {
        $row = [
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::operation => 'restore',
            ProtectedModeRuntime::initiatorAcceptKey => 'accept-9',
            ProtectedModeRuntime::initiatorAgentType => 'backup',
            ProtectedModeRuntime::initiatorAgentIndex => 0,
            ProtectedModeRuntime::initiatorNodeId => 'node-a',
            ProtectedModeRuntime::startedAt => 1_700_000_000,
            ProtectedModeRuntime::activatedAt => 1_700_000_005,
        ];

        $runtime = ProtectedModeRuntime::fromRow($row);

        $this->assertSame($row, $runtime->toArray());
        $this->assertSame(0, $runtime->initiatorAgentIndex);
        $this->assertSame(1_700_000_000, $runtime->startedAt);
    }

    public function testRuntimeApplyDiffOverwritesOnlyPresentFields(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVATING,
            ProtectedModeRuntime::operation => 'restore',
            ProtectedModeRuntime::startedAt => 1_700_000_000,
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

        $this->assertFalse($runtime->locksOut('accept-9'));
        $this->assertFalse($runtime->locksOut(null));
    }

    public function testActiveRuntimeLocksOutEveryConnectionButTheInitiator(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::initiatorAcceptKey => 'accept-initiator',
        ]);

        $this->assertFalse($runtime->locksOut('accept-initiator'));
        $this->assertTrue($runtime->locksOut('accept-other'));
        $this->assertTrue($runtime->locksOut(null));
    }

    public function testFollowerRuntimeLocksOutEveryoneWhileMerelyActivating(): void
    {
        // A follower quiesces to `activating` with no initiator key and never advances to
        // `active`, so the lockdown must engage on any non-inactive phase and lock every real
        // (server-minted, non-null) connection out.
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVATING,
            ProtectedModeRuntime::initiatorAcceptKey => null,
        ]);

        $this->assertTrue($runtime->locksOut('accept-9'));
        $this->assertTrue($runtime->locksOut('accept-other'));
    }

    public function testDeactivatingRuntimeStillLocksOutNonInitiator(): void
    {
        $runtime = ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_DEACTIVATING,
            ProtectedModeRuntime::initiatorAcceptKey => 'accept-initiator',
        ]);

        $this->assertFalse($runtime->locksOut('accept-initiator'));
        $this->assertTrue($runtime->locksOut('accept-other'));
    }

    public function testEnableSignalDataRoundTrips(): void
    {
        $data = new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
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
}

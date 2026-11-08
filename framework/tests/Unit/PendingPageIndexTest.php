<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\PendingPageDelivery;
use Hilos\Core\Daemon\PendingPageEntry;
use Hilos\Core\Daemon\PendingPageIndex;
use PHPUnit\Framework\TestCase;
use WeakReference;

/** Indexed selection must preserve original per-connection attempt order and exact address lifetimes. */
final class PendingPageIndexTest extends TestCase
{
    /** @var array<string, array<string, PendingPageDelivery>> Sole original payload ledger */
    private array $payloads = [];

    private PendingPageIndex $index;

    protected function setUp(): void
    {
        $this->index = new PendingPageIndex(
            fn(string $acceptKey, string $deliveryId): ?PendingPageDelivery => $this->payloads[$acceptKey][$deliveryId] ?? null,
            function (PendingPageEntry $entry): void {
                unset($this->payloads[$entry->acceptKey][$entry->deliveryId]);
            },
        );
    }

    public function testRecoveryKeepsOriginalAttemptOrderAfterTheFirstTokenIsAddressedAgain(): void
    {
        $this->record('socket', 'p', 'first', 1);
        $this->record('socket', 'p', 'second', 2);
        $this->record('socket', 'p', 'first', 3);
        $tokens = [];
        foreach ($this->index->recoveryCandidates(['owner']) as $sourceId) {
            $pending = $sourceId === null ? null : $this->index->candidate($sourceId);
            if ($pending !== null) {
                $tokens[] = $pending->deliveryId;
            }
        }
        self::assertSame(['first', 'second'], $tokens);
        self::assertSame('second', $this->index->last('socket')->deliveryId);
        self::assertSame(['id' => 3], $this->index->get('socket', 'first')->params);
    }

    public function testLogicalPageCancellationRevealsAnotherPageWithoutDrainingTheRetiredPayloads(): void
    {
        $this->record('socket', 'p', 'first', 1);
        $this->record('socket', 'q', 'other-page', 2);
        $this->record('socket', 'p', 'last-p', 3);
        $reference = WeakReference::create($this->index->get('socket', 'last-p'));
        $cleanup = $this->index->cancelConnection('socket', 'p');
        self::assertNull($this->index->get('socket', 'first'));
        self::assertNull($this->index->get('socket', 'last-p'));
        self::assertSame('other-page', $this->index->last('socket')->deliveryId);
        self::assertNotNull($reference->get());
        self::assertFalse($this->index->hasPage('socket', 'p'));
        self::assertTrue($this->index->hasPage('socket', 'q'));
        $this->record('socket', 'p', 'future', 4);
        $this->record('socket', 'q', 'other-page', 22);
        self::assertSame('future', $this->index->last('socket')->deliveryId);
        iterator_to_array($cleanup);
        self::assertNull($reference->get());
        self::assertSame(['other-page', 'future'], array_keys($this->payloads['socket']));
        $this->index->remove('socket', 'future');
        self::assertSame('other-page', $this->index->last('socket')->deliveryId);
        self::assertSame(['id' => 22], $this->index->last('socket')->params);
        $this->index->remove('socket', 'other-page');
        self::assertNull($this->index->last('socket'));
    }

    public function testWholeConnectionCleanupCannotRemoveAReplacementWithTheSameToken(): void
    {
        $this->record('socket', 'p', 'same-token', 1);
        $cleanup = $this->index->cancelConnection('socket');
        self::assertNull($this->index->get('socket', 'same-token'));
        $this->record('socket', 'p', 'same-token', 2);
        iterator_to_array($cleanup);
        self::assertSame(['id' => 2], $this->index->get('socket', 'same-token')->params);
        self::assertSame('same-token', $this->index->last('socket')->deliveryId);
    }

    public function testCapturedAddressCohortsExcludeReaddressedAndFutureRecords(): void
    {
        $this->record('local', 'p', 'local', 1);
        $this->record('remote', 'p', 'remote', 2, 'node-B');
        $local = $this->index->recoveryCandidates(['owner']);
        $remote = $this->index->recoveryCandidates(nodeId: 'node-B');
        $page = $this->index->recoveryCandidates(acceptKey: 'local', page: 'p');
        $this->record('local', 'p', 'local', 3, 'node-B');
        $this->record('future', 'p', 'future', 4, 'node-B');
        foreach ([$local, $page] as $candidates) {
            foreach ($candidates as $sourceId) {
                self::assertNull($sourceId === null ? null : $this->index->candidate($sourceId));
            }
        }
        $tokens = [];
        foreach ($remote as $sourceId) {
            $pending = $sourceId === null ? null : $this->index->candidate($sourceId);
            if ($pending !== null) {
                $tokens[] = $pending->deliveryId;
            }
        }
        self::assertSame(['remote'], $tokens);
    }

    /**
     * @param string $acceptKey Original connection
     * @param string $page Original page
     * @param string $deliveryId Exact attempt token
     * @param int $value Distinct original payload value
     * @param ?string $nodeId Selected remote node, absent for the local agent
     */
    private function record(string $acceptKey, string $page, string $deliveryId, int $value, ?string $nodeId = null): void
    {
        $delivery = new PendingPageDelivery($acceptKey, $page, $deliveryId, ['id' => $value], 'owner', $nodeId);
        $this->index->record($delivery);
        $this->payloads[$acceptKey][$deliveryId] = $delivery;
    }
}

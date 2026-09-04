<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeSet;
use Hilos\Core\Table\Mutation\TableMutationType;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the browser source-change buffer.
 */
final class BrowserContextSourceChangeBufferTest extends TestCase
{
    public function testFlushGroupsAndEmitsDbRtSourceChangesThenDrainsBuffer(): void
    {
        $context = new BrowserContextSourceChangeBufferTestContext();

        $context->record(SourceChange::dbCreated('users', '1', ['name' => 'Ada']));
        $context->record(SourceChange::dbUpdated('users', '1', ['name' => 'Grace']));
        $context->record(SourceChange::dbDeleted('users', '1', ['name' => 'Grace']));
        $context->record(SourceChange::rtCreated('connections', 'ak-1', ['userId' => 1]));
        $context->record(SourceChange::rtUpdated('connections', 'ak-1', ['presence' => 'online']));
        $context->record(SourceChange::rtDeleted('connections', 'ak-1', ['presence' => 'online']));

        $this->assertTrue($context->hasChanges());

        $context->flushToSignalRouter();

        $this->assertFalse($context->hasChanges());
        $this->assertCount(1, $context->groupedChangeSets);
        $this->assertSame($context->groupedChangeSets, $context->emittedChangeSets);

        $changes = $context->emittedChangeSets[0]->all();
        $this->assertCount(2, $changes);
        $this->assertSame(
            [
                SourceChange::KIND_DB,
                SourceChange::KIND_RT,
            ],
            array_map(static fn(SourceChange $change): string => $change->kind, $changes),
        );
        $this->assertSame(
            [
                TableMutationType::Delete,
                TableMutationType::Delete,
            ],
            array_map(static fn(SourceChange $change): TableMutationType => $change->mutationType, $changes),
        );
        $this->assertSame(['name' => 'Grace'], $changes[0]->row);
        $this->assertSame(['userId' => 1, 'presence' => 'online'], $changes[1]->row);
    }

    public function testFlushMergesRepeatedUpdatesForSameSourceItem(): void
    {
        $context = new BrowserContextSourceChangeBufferTestContext();

        $context->record(SourceChange::dbUpdated('users', '1', ['name' => 'Ada']));
        $context->record(SourceChange::dbUpdated('users', '1', ['lastActivity' => '2026-05-12 10:00:00']));
        $context->record(SourceChange::dbUpdated('users', '1', ['name' => 'Grace']));
        $context->record(SourceChange::dbUpdated('users', '2', ['name' => 'Lin']));

        $context->flushToSignalRouter();

        $changes = $context->emittedChangeSets[0]->all();
        $this->assertCount(2, $changes);
        $this->assertSame('1', $changes[0]->sourceId);
        $this->assertSame(TableMutationType::Update, $changes[0]->mutationType);
        $this->assertSame(
            [
                'name' => 'Grace',
                'lastActivity' => '2026-05-12 10:00:00',
            ],
            $changes[0]->row,
        );
        $this->assertSame('2', $changes[1]->sourceId);
        $this->assertSame(['name' => 'Lin'], $changes[1]->row);
    }

    public function testFlushCarriesTheLaterWriterActionAlongWithItsOrigin(): void
    {
        $context = new BrowserContextSourceChangeBufferTestContext();

        // Two presses landing on the same row in one tick: the later one wins the row,
        // so it must also be the one the echo names.
        $context->record(SourceChange::dbUpdated('users', '1', ['name' => 'Ada'], 'ak-2', 'req-2'));
        $context->record(SourceChange::dbUpdated('users', '1', ['name' => 'Grace'], 'ak-1', 'req-1'));

        $context->flushToSignalRouter();

        $changes = $context->emittedChangeSets[0]->all();
        $this->assertCount(1, $changes);
        $this->assertSame('ak-1', $changes[0]->origin);
        $this->assertSame('req-1', $changes[0]->originRequestId);
    }

    public function testFlushKeepsCreateWhenCreatedSourceItemIsUpdated(): void
    {
        $context = new BrowserContextSourceChangeBufferTestContext();

        $context->record(SourceChange::dbCreated('users', '1', ['id' => 1, 'name' => 'Ada']));
        $context->record(SourceChange::dbUpdated('users', '1', ['name' => 'Grace']));

        $context->flushToSignalRouter();

        $changes = $context->emittedChangeSets[0]->all();
        $this->assertCount(1, $changes);
        $this->assertSame(TableMutationType::Create, $changes[0]->mutationType);
        $this->assertSame(['id' => 1, 'name' => 'Grace'], $changes[0]->row);
    }

    public function testFlushKeepsClearAsSeparateGroupFromRowChanges(): void
    {
        $context = new BrowserContextSourceChangeBufferTestContext();

        // A deleteAll() truncate followed by a fresh marker row in the same tick.
        $context->record(SourceChange::dbCleared('events'));
        $context->record(SourceChange::dbCreated('events', '7', ['id' => 7, 'type' => 'chat_cleared']));

        $context->flushToSignalRouter();

        $changes = $context->emittedChangeSets[0]->all();
        $this->assertCount(2, $changes);

        $this->assertSame(SourceChange::KIND_DB, $changes[0]->kind);
        $this->assertSame('events', $changes[0]->sourceKey);
        $this->assertSame('', $changes[0]->sourceId);
        $this->assertSame(TableMutationType::Clear, $changes[0]->mutationType);
        $this->assertSame([], $changes[0]->row);

        $this->assertSame('7', $changes[1]->sourceId);
        $this->assertSame(TableMutationType::Create, $changes[1]->mutationType);
    }

    public function testFlushMergesRepeatedClearsForSameCollection(): void
    {
        $context = new BrowserContextSourceChangeBufferTestContext();

        $context->record(SourceChange::dbCleared('events'));
        $context->record(SourceChange::dbCleared('events'));

        $context->flushToSignalRouter();

        $changes = $context->emittedChangeSets[0]->all();
        $this->assertCount(1, $changes);
        $this->assertSame(TableMutationType::Clear, $changes[0]->mutationType);
        $this->assertSame('events', $changes[0]->sourceKey);
    }

    public function testFlushTreatsDeleteThenCreateAsUpdateWithReplacementRow(): void
    {
        $context = new BrowserContextSourceChangeBufferTestContext();

        $context->record(SourceChange::dbDeleted('users', '1', ['id' => 1, 'name' => 'Ada', 'stale' => true]));
        $context->record(SourceChange::dbCreated('users', '1', ['id' => 1, 'name' => 'Grace']));

        $context->flushToSignalRouter();

        $changes = $context->emittedChangeSets[0]->all();
        $this->assertCount(1, $changes);
        $this->assertSame(TableMutationType::Update, $changes[0]->mutationType);
        $this->assertSame(['id' => 1, 'name' => 'Grace'], $changes[0]->row);
    }
}

final class BrowserContextSourceChangeBufferTestContext extends BrowserContext
{
    /** @var list<SourceChangeSet> */
    public array $groupedChangeSets = [];

    /** @var list<SourceChangeSet> */
    public array $emittedChangeSets = [];

    /**
     * Records the changes that reached the grouping hook.
     */
    protected function groupSourceChanges(): void
    {
        parent::groupSourceChanges();

        $this->groupedChangeSets[] = $this->changes;
    }

    /**
     * Records the changes that reached the final browser emit hook.
     *
     * @return list<ContainedFailure> Nothing: this stub emits no signals and fails on none
     */
    protected function emitBrowserSignals(): array
    {
        $this->emittedChangeSets[] = $this->changes;

        return [];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Projection\SourceChangeSet;
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
        $this->assertCount(6, $changes);
        $this->assertSame(
            [
                SourceChange::KIND_DB,
                SourceChange::KIND_DB,
                SourceChange::KIND_DB,
                SourceChange::KIND_RT,
                SourceChange::KIND_RT,
                SourceChange::KIND_RT,
            ],
            array_map(static fn(SourceChange $change): string => $change->kind, $changes),
        );
        $this->assertSame(
            [
                TableMutationType::Create,
                TableMutationType::Update,
                TableMutationType::Delete,
                TableMutationType::Create,
                TableMutationType::Update,
                TableMutationType::Delete,
            ],
            array_map(static fn(SourceChange $change): TableMutationType => $change->mutationType, $changes),
        );
    }
}

final class BrowserContextSourceChangeBufferTestContext extends BrowserContext
{
    /** @var list<SourceChangeSet> */
    public array $groupedChangeSets = [];

    /** @var list<SourceChangeSet> */
    public array $emittedChangeSets = [];

    public function configure(): void
    {
    }

    /**
     * Records the changes that reached the grouping hook.
     */
    protected function groupSourceChanges(): void
    {
        $this->groupedChangeSets[] = $this->changes;

        parent::groupSourceChanges();
    }

    /**
     * Records the changes that reached the final browser emit hook.
     */
    protected function emitBrowserSignals(): void
    {
        $this->emittedChangeSets[] = $this->changes;
    }
}

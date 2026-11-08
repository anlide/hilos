<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\TruthSource\DbWriterClaim;
use Hilos\Core\TruthSource\DbWriterClaimWidth;
use Hilos\Core\TruthSource\DbWriterPartition;
use Hilos\Core\TruthSource\DbWriterTransitionState;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use PHPUnit\Framework\TestCase;

/** Typed DB topology metadata, independent of the still-pending closed catalog and judge. */
final class DbWriterClaimTest extends TestCase
{
    public function testStateKeepsExactClaimsAndCanonicalPendingLeaves(): void
    {
        $claim = new DbWriterClaim(AbstractAgent::class, DbWriterClaimWidth::Rows, TruthSourceOperations::of(TruthSourceOperation::Update));
        $state = new DbWriterTransitionState(['HIL-1999', 'HIL-1952', 'HIL-1952'], [$claim], [DbWriterPartition::NotificationChannelShard]);

        self::assertSame(['HIL-1952', 'HIL-1999'], $state->pendingLeaves);
        self::assertSame([$claim], $state->claims);
        self::assertSame(AbstractAgent::class, $claim->ownerClass);
        self::assertSame([TruthSourceOperation::Update], $claim->operations->asList());
        self::assertSame([DbWriterPartition::NotificationChannelShard], $state->partitions);
        self::assertSame([], (new DbWriterTransitionState([], []))->partitions);
    }

    public function testWidthsAndProofHaveClosedValuesAndProductionDefaultIsNull(): void
    {
        self::assertSame(['whole', 'rows', 'set'], array_column(DbWriterClaimWidth::cases(), 'value'));
        self::assertSame(['notification-channel-shard'], array_column(DbWriterPartition::cases(), 'value'));
        self::assertNull(AbstractAgent::DB_WRITER_PARTITION);
    }

    public function testNormalizerUsesInheritedExpandedDeclarationsInEveryWidth(): void
    {
        $claims = DbWriterClaim::declarationsOf(DbWriterClaimChildAgent::class);

        self::assertSame(['wholeRows', 'namedRows', 'ownedSet'], array_keys($claims));
        foreach ($claims as $declarations) {
            self::assertCount(1, $declarations);
            self::assertSame(DbWriterClaimChildAgent::class, $declarations[0]->ownerClass);
        }
        self::assertSame(DbWriterClaimWidth::Whole, $claims['wholeRows'][0]->width);
        self::assertSame([TruthSourceOperation::Update, TruthSourceOperation::Add], $claims['wholeRows'][0]->operations->asList());
        self::assertSame(DbWriterClaimWidth::Rows, $claims['namedRows'][0]->width);
        self::assertSame([TruthSourceOperation::Remove], $claims['namedRows'][0]->operations->asList());
        self::assertSame(DbWriterClaimWidth::Set, $claims['ownedSet'][0]->width);
        self::assertSame([TruthSourceOperation::Add], $claims['ownedSet'][0]->operations->asList());
        self::assertSame([], DbWriterClaim::declarationsOf(AbstractAgent::class));
    }
}

abstract class DbWriterClaimParentAgent extends AbstractAgent
{
    public const array OWNS_DB = ['wholeRows' => TruthSourceOperation::BY_KIND];

    public const array OWNS_DB_ROWS = ['namedRows' => [TruthSourceOperation::Remove]];

    public const array OWNS_DB_SET = ['ownedSet' => [TruthSourceOperation::Add]];

    /** @return TruthSourceOperations Creation alone for a declaration that uses BY_KIND */
    public static function defaultTruthSourceOperations(): TruthSourceOperations
    {
        return TruthSourceOperations::of(TruthSourceOperation::Add);
    }
}

abstract class DbWriterClaimChildAgent extends DbWriterClaimParentAgent
{
    public const array OWNS_DB = ['wholeRows' => [TruthSourceOperation::Update]];
}

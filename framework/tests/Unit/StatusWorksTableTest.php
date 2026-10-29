<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Pages\Status\AbstractHilosStatusWorksPage;
use Hilos\Tables\Status\HilosStatusWorksTable;
use Hilos\Tables\Status\HilosStatusWorksTableRow;
use PHPUnit\Framework\TestCase;

/** Wire identity, bridge addressing and anchors; real reads and live membership are covered by integration. */
final class StatusWorksTableTest extends TestCase
{
    public function testExactRowEnvelopeAndSixOrdersUseTheStandardTableContract(): void
    {
        $wire = ['id' => 17, 'publicId' => str_repeat('a', 32), 'title' => 'Upgrade', 'description' => null,
            'componentKeys' => ['service'], 'startsAt' => 10000, 'endsAt' => 20000, 'version' => 2,
            'canceledAt' => null, 'consumedAt' => null, 'expiredAt' => null, 'lastMailQueuedAt' => 9000];
        $table = new HilosStatusWorksTable();
        $row = HilosStatusWorksTableRow::fromArray($wire + ['actorId' => 1, 'mailAudience' => 'private']);
        self::assertSame($wire, $row->toArray());
        self::assertSame(['rowKey' => 17, 'sources' => ['work' => $wire]], $table->browserRow($row));
        self::assertEqualsCanonicalizing(array_keys($wire), array_keys($table->wireFields()));
        self::assertSame([['field' => 'startsAt', 'direction' => 'asc']], $table->defaultSort()->toArray());
        self::assertSame([], $table->bulkActions());
        self::assertNull($table->getItemActionsClass());
        $query = new TableQueryDTO(sort: $table->defaultSort());
        $anchor = $table->anchorForRow($row, $query);
        self::assertSame(['starts_at' => 10000, 'id' => 17], $anchor->values);
        self::assertSame(0, $table->placeRowAgainst($row, $anchor, $query));
    }

    public function testADeletedComponentAddressesItsWorkInsteadOfItsOwnPrimaryId(): void
    {
        $table = new HilosStatusWorksTable();
        self::assertSame(17, $table->rowKeyForSourceEvent(SourceChange::dbDeleted(
            HilosDbContext::statusWorkComponents, '991', ['id' => 991, 'work_id' => 17, 'component_key' => 'service'])));
        self::assertSame(17, $table->rowKeyForSourceEvent(SourceChange::dbUpdated(
            HilosDbContext::statusWorkComponents, '991', ['component_key' => 'mail'], previous: ['work_id' => 17])));
        self::assertSame(17, $table->rowKeyForSourceEvent(SourceChange::dbUpdated(HilosDbContext::statusWorks, '17', [])));
        self::assertNull($table->rowKeyForSourceEvent(SourceChange::dbUpdated(HilosDbContext::statusSubscribers, '17', [])));
        self::assertSame(PageAccessLevel::ADMIN, AbstractHilosStatusWorksPage::ACCESS_LEVEL);
        self::assertSame(HilosPageConstants::HILOS_STATUS_WORKS, AbstractHilosStatusWorksPage::PAGE);
        self::assertTrue(AbstractHilosStatusWorksPage::observesOverviewChange(
            SourceChange::dbUpdated(HilosDbContext::statusWorkComponents, '991', [])));
    }
}

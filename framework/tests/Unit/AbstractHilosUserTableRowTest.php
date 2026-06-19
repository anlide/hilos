<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Tables\Users\AbstractHilosUserTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework base row of the Hilos users table.
 */
final class AbstractHilosUserTableRowTest extends TestCase
{
    public function testGetRowKeyIsId(): void
    {
        $this->assertSame(42, $this->row(42)->getRowKey());
    }

    public function testBaseFieldsExposeIdentityRbacAndPresence(): void
    {
        $row = $this->row(42, admin: true, block: false, onlineSessionCount: 3, presence: 'online');

        $this->assertSame(
            [
                AbstractHilosUserTableRow::id => 42,
                AbstractHilosUserTableRow::admin => true,
                AbstractHilosUserTableRow::block => false,
                AbstractHilosUserTableRow::onlineSessionCount => 3,
                AbstractHilosUserTableRow::presence => 'online',
            ],
            $row->toArray(),
        );
    }

    public function testPresenceFieldNamesMatchTheSummaryDto(): void
    {
        $this->assertSame('presence', AbstractHilosUserTableRow::presence);
        $this->assertSame('onlineSessionCount', AbstractHilosUserTableRow::onlineSessionCount);
    }

    /**
     * Builds a minimal concrete base row for assertions.
     *
     * @param int $id Row id and key
     * @param bool $admin Admin flag
     * @param bool $block Block flag
     * @param int $onlineSessionCount Active session count
     * @param ?string $presence Presence value
     * @return AbstractHilosUserTableRow Concrete base row exposing only the base fields
     */
    private function row(
        int $id,
        bool $admin = false,
        bool $block = false,
        int $onlineSessionCount = 0,
        ?string $presence = null,
    ): AbstractHilosUserTableRow {
        return new class($id, $admin, $block, $onlineSessionCount, $presence) extends AbstractHilosUserTableRow {
            public function toArray(): array
            {
                return $this->baseFields();
            }

            public static function fromArray(array $data): static
            {
                return new static((int) ($data[self::id] ?? 0));
            }
        };
    }
}

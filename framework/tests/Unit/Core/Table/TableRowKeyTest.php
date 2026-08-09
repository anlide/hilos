<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Tests that a row which has to be addressable refuses to answer with no key (HIL-544).
 *
 * A browser row, a window entry and a delta all address one row by its key. A placeholder row
 * has none, and the empty string that used to stand in for it addressed no row at all — while
 * making every keyless row look like the same row to the window storing them.
 */
final class TableRowKeyTest extends TestCase
{
    public function testAKeyedRowAnswersWithItsKey(): void
    {
        self::assertSame('settings.theme', $this->row('settings.theme')->requireRowKey());
        self::assertSame(7, $this->row(7)->requireRowKey());
    }

    public function testAPlaceholderRowRefusesToInventAKey(): void
    {
        $this->expectException(TableRowKeyMissingException::class);
        $this->row(null)->requireRowKey();
    }

    /**
     * Builds a row carrying the given key.
     *
     * @param string|int|null $rowKey Key the row answers with, or null for a placeholder
     * @return AbstractTableRow Row under test
     */
    private function row(string|int|null $rowKey): AbstractTableRow
    {
        return new class ($rowKey) extends AbstractTableRow {
            /**
             * @param string|int|null $rowKey Key the row answers with
             */
            public function __construct(private readonly string|int|null $rowKey)
            {
            }

            public function getRowKey(): string|int|null
            {
                return $this->rowKey;
            }

            /**
             * @return array<string, mixed> Row payload
             */
            public function toArray(): array
            {
                return ['rowKey' => $this->rowKey];
            }

            /**
             * @param array<string, mixed> $data Row payload
             * @return static Restored row
             */
            public static function fromArray(array $data): static
            {
                /** @var string|int|null $rowKey */
                $rowKey = $data['rowKey'] ?? null;

                return new static($rowKey);
            }
        };
    }
}

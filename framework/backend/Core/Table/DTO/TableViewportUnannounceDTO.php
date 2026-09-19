<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * TableViewportUnannounceDTO - Server-to-client live word that a row left the set outside the window.
 *
 * The mirror of {@see TableViewportAnnounceDTO}: the window was possibly told about a row it
 * cannot show, and that row is gone again. The server does not remember what it announced to
 * whom, so this goes to every window that does not hold the row, and a window that was never
 * told about the key drops the word. It carries the key and nothing else - no place, the row
 * having none anymore, and no count, the total travelling on its own frame.
 * Addressed per accept key.
 */
final class TableViewportUnannounceDTO extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string rowKey = 'rowKey';

    /**
     * Creates a table viewport unannounce payload.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the row was announced on
     * @param string $rowKey Key of the row that left the set
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly string $rowKey,
    ) {
    }

    /**
     * Converts the payload to its wire array.
     *
     * @return array<string, mixed> DTO payload in the table-viewport-unannounce wire form
     */
    public function toArray(): array
    {
        return [
            self::page => $this->page,
            self::tableKey => $this->tableKey,
            self::rowKey => $this->rowKey,
        ];
    }

    /**
     * Restores the payload from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-viewport-unannounce wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses the addressed table or the row key
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: self::requireString($data, self::page),
            tableKey: self::requireString($data, self::tableKey),
            rowKey: self::requireString($data, self::rowKey),
        );
    }
}

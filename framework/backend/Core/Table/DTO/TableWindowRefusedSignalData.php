<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\TableWindowRefusalCode;

/**
 * TableWindowRefusedSignalData - Server-to-client refusal of one table's window.
 *
 * Sent only in reply to a client table_viewport request or a page's re-send of
 * a window — never in the live stream. The client reads {@see TableWindowRefusalCode},
 * not a sentence: the server's own error text does not go on the wire, and the
 * view draws one phrase for every code.
 */
final class TableWindowRefusedSignalData extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string errorCode = 'errorCode';

    /**
     * Creates a table-window refusal signal payload.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key whose window is refused
     * @param string $errorCode Machine-readable reason ({@see TableWindowRefusalCode})
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly string $errorCode,
    ) {
    }

    /**
     * Converts the signal payload to its wire array.
     *
     * @return array<string, mixed> DTO payload in the table-window-refusal wire form
     */
    public function toArray(): array
    {
        return [
            self::page => $this->page,
            self::tableKey => $this->tableKey,
            self::errorCode => $this->errorCode,
        ];
    }

    /**
     * Restores the signal payload from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-window-refusal wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses page, tableKey, or errorCode
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: self::requireString($data, self::page),
            tableKey: self::requireString($data, self::tableKey),
            errorCode: self::requireString($data, self::errorCode),
        );
    }
}

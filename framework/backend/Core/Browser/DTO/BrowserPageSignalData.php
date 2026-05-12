<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Browser page state changes shaped by the subscribed page config.
 */
final class BrowserPageSignalData extends BaseDTO implements SignalDataInterface
{
    public const string tables = 'tables';
    public const string rows = 'rows';
    public const string deleted = 'deleted';
    public const string rowKey = 'rowKey';
    public const string sources = 'sources';

    /**
     * @param array<string, array{
     *     rows?: list<array{rowKey: int|string, sources: array<string, mixed>}>,
     *     deleted?: list<int|string>
     * }> $tables Browser table changes keyed by table name
     */
    public function __construct(
        private readonly array $tables = [],
    ) {
    }

    /**
     * Converts page-shaped browser changes to WebSocket payload.
     *
     * @return array<string, mixed> Browser page signal payload
     */
    public function toArray(): array
    {
        return $this->tables === [] ? [] : [self::tables => $this->tables];
    }

    /**
     * Restores browser page changes from the WebSocket payload.
     *
     * @param array<string, mixed> $data Browser page signal payload
     * @return static Restored browser page signal data
     */
    public static function fromArray(array $data): static
    {
        $tables = $data[self::tables] ?? [];

        return new self(is_array($tables) ? $tables : []);
    }
}

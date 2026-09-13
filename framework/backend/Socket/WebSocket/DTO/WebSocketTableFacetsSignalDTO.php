<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\WebSocket\Exception\InvalidFrameException;

/**
 * WebSocketTableFacetsSignalDTO - DTO for the options a client asks counts beside, for one table.
 *
 * Sent by the view when it mounts, and again whenever the options it offers change: a list that
 * arrives in the page scope later is picked up on its own. The options are the client's to name -
 * a page declares them on the front end - and the server knows nothing of them until this frame
 * arrives. The server keeps the list beside the connection's window for that table, answers with
 * the counts at once, and sends them again after every change of the set without being asked.
 *
 * An option value is a scalar and nothing else. Anything else is dropped on reading, and so is a
 * filter whose entry is not a list at all; neither refuses the frame, because a number beside an
 * option is a decoration of a choice and a malformed option costs its own number and no more.
 */
class WebSocketTableFacetsSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string TABLE_KEY = 'tableKey';
    public const string FACETS = 'facets';

    /**
     * Creates a table facets signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param ?string $page Page the table belongs to, null when the signal name carries it
     * @param string $tableKey Table key the options are for
     * @param array<string, list<int|float|string|bool>> $facets Options to count, by filter key
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $page = null,
        public readonly string $tableKey = '',
        public readonly array $facets = [],
    ) {
    }

    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        $result = [
            self::ACCEPT_KEY => $this->acceptKey,
            self::TABLE_KEY => $this->tableKey,
            self::FACETS => $this->facets,
        ];

        if ($this->page !== null) {
            $result[self::PAGE] = $this->page;
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * The map of options is what the frame is for and is required, even when it is empty: a view
     * whose filters offer nothing to count still says so, and the list it replaces is dropped.
     *
     * Built straight from a client frame, like {@see WebSocketTableViewportSignalDTO}, so the refusal
     * is translated into {@see InvalidFrameException} where the frame is read
     * ({@see WebSocketClient::onFrame()}) and stays the plain refusal on the master-worker hop.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the frame carries no accept key, no table key or no map of options
     */
    public static function fromArray(array $data): static
    {
        $page = self::optionalString($data, self::PAGE);

        return new static(
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
            page: $page === '' ? null : $page,
            tableKey: self::requireString($data, self::TABLE_KEY),
            facets: TableFacetTally::wantedOptions(self::requireArray($data, self::FACETS)),
        );
    }
}

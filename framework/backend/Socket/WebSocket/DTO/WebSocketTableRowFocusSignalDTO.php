<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\WebSocket\Exception\InvalidFrameException;

/**
 * WebSocketTableRowFocusSignalDTO - DTO for the row of one table a tab holds in focus for an open dialog.
 *
 * Sent by the view when an edit or delete dialog opens over a row of a table window, and again with
 * every window the tab receives while the dialog stays open (HIL-1050). The server keeps the row
 * beside the connection's facets rather than inside its window, and follows it for this tab wherever
 * it goes: the change that takes the row out of the window - out of the set, past an edge - travels
 * as the `row_removed` delta that always did, now carrying the row's body, and a change to a row
 * already outside the window travels the same way. A row the table's own set no longer holds gets
 * no body: that set is the boundary of what the tab may read.
 *
 * An empty row key releases the focus. The server answers nothing to a focus on a row the window
 * holds - the window's own frames carry it - and answers one delta when the row is outside the
 * window, so a focus re-sent after a reconnect brings the tab the row's current body.
 */
class WebSocketTableRowFocusSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string TABLE_KEY = 'tableKey';
    public const string ROW_KEY = 'rowKey';

    /**
     * Creates a table row focus signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param ?string $page Page the table belongs to, null when the signal name carries it
     * @param string $tableKey Table key the row belongs to
     * @param string $rowKey Row the tab holds in focus, empty to release the focus
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $page = null,
        public readonly string $tableKey = '',
        public readonly string $rowKey = '',
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
            self::ROW_KEY => $this->rowKey,
        ];

        if ($this->page !== null) {
            $result[self::PAGE] = $this->page;
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * The row key is what the frame is for and is required, empty included: an empty key is the
     * release, and a frame without the key names no row to hold or to let go of. A key of another
     * type refuses the frame rather than being cast: a number cast to a string would hold a row
     * the tab never named.
     *
     * Built straight from a client frame, like {@see WebSocketTableViewportSignalDTO}, so the refusal
     * is translated into {@see InvalidFrameException} where the frame is read
     * ({@see WebSocketClient::onFrame()}) and stays the plain refusal on the master-worker hop.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the frame carries no accept key, no table key or no string row key
     */
    public static function fromArray(array $data): static
    {
        $page = self::optionalString($data, self::PAGE);

        return new static(
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
            page: $page === '' ? null : $page,
            tableKey: self::requireString($data, self::TABLE_KEY),
            rowKey: self::requireString($data, self::ROW_KEY),
        );
    }
}

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
 * WebSocketTableRenderedSignalDTO - DTO for the fields of one table's rows a client's columns draw.
 *
 * Sent by the view once, when the first window of the table arrives with the page's own answer
 * and the tab did not ask for that window itself (HIL-880). Every window the tab asks for carries
 * the same list in its own frame ({@see WebSocketTableViewportSignalDTO::RENDERED}), and so does
 * the report of a held window at a page subscription; the one window that cannot is the cold
 * entry, which the server builds from the table's declaration before the table is mounted. This
 * frame is how that window learns what is drawn of its rows.
 *
 * The server asks nothing back and sends nothing: it keeps the list on the connection's window for
 * the table, so a later change of a field no cell draws raises no delta.
 */
class WebSocketTableRenderedSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string TABLE_KEY = 'tableKey';
    public const string RENDERED = 'rendered';

    /**
     * Creates a table rendered signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param ?string $page Page the table belongs to, null when the signal name carries it
     * @param string $tableKey Table key the fields are drawn by
     * @param list<string> $rendered Fields inside the row slots the tab draws, empty when it draws none it can name
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $page = null,
        public readonly string $tableKey = '',
        public readonly array $rendered = [],
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
            self::RENDERED => $this->rendered,
        ];

        if ($this->page !== null) {
            $result[self::PAGE] = $this->page;
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * The list is what the frame is for and is required. An entry that is not a string refuses the
     * frame rather than dropping out of the list: a field left out would be a field whose changes
     * the server then keeps from the screen.
     *
     * Built straight from a client frame, like {@see WebSocketTableViewportSignalDTO}, so the refusal
     * is translated into {@see InvalidFrameException} where the frame is read
     * ({@see WebSocketClient::onFrame()}) and stays the plain refusal on the master-worker hop.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the frame carries no accept key, no table key or no list of strings
     */
    public static function fromArray(array $data): static
    {
        $page = self::optionalString($data, self::PAGE);

        return new static(
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
            page: $page === '' ? null : $page,
            tableKey: self::requireString($data, self::TABLE_KEY),
            rendered: self::optionalStringList($data, self::RENDERED)
                ?? throw new InvalidFormatException('Table rendered frame carries no list of drawn fields'),
        );
    }
}

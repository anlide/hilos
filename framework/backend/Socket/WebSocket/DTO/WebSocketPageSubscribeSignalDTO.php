<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;

/**
 * WebSocketPageSubscribeSignalDTO - DTO for WebSocket page subscribe signal.
 *
 * Represents a page subscription signal sent from WebSocket client.
 *
 * Besides the address of the page it also carries the windows the tab is already holding, one
 * descriptor per table key (HIL-642). This is the only frame that can carry them: the server
 * forgets a window with the accept key it was made under, so after a broken socket the tab is
 * the only side that still knows what was on the screen, and it says so in the frame the
 * reconnect already sends. A cold entry has no controller bound yet and so carries none — one
 * rule, two scenes, and no flag saying which one this is.
 */
class WebSocketPageSubscribeSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string PARAMS = 'params';
    public const string TABLE_WINDOWS = 'tableWindows';

    /**
     * Creates page subscribe signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param ?string $page Page name, null when the signal name carries it
     * @param array<string, string> $params Route params
     * @param array<string, TableWindowDescriptorDTO> $tableWindows Windows the tab holds, by table key
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $page = null,
        public readonly array $params = [],
        public readonly array $tableWindows = [],
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
        ];

        if ($this->page !== null) {
            $result[self::PAGE] = $this->page;
        }

        if (!empty($this->params)) {
            $result[self::PARAMS] = $this->params;
        }

        if ($this->tableWindows !== []) {
            $result[self::TABLE_WINDOWS] = array_map(
                static fn(TableWindowDescriptorDTO $descriptor): array => $descriptor->toArray(),
                $this->tableWindows,
            );
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * The page and the params stay optional: toArray() leaves either key out
     * when it has nothing to write, so an absent one reads as the absence it was.
     * The windows are optional the same way, and an absent key says what an empty
     * map says — this tab is holding no window.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no accept key, or a window
     *     descriptor carries no limit or addresses its window two ways at once
     */
    public static function fromArray(array $data): static
    {
        $page = self::optionalString($data, self::PAGE);

        return new static(
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
            page: $page === '' ? null : $page,
            params: self::optionalArray($data, self::PARAMS) ?? [],
            tableWindows: self::readTableWindows($data),
        );
    }

    /**
     * Reads the windows a tab reported, dropping an entry that is not a descriptor at all.
     *
     * A table key naming something other than a map is a frame nobody sends, and the honest
     * answer to it is the cold entry: the table gets the window it declares for itself. A map
     * that IS a descriptor and contradicts itself is another matter and refuses the frame,
     * because there the tab did mean a window and the server would have to guess which.
     *
     * @param array<string, mixed> $data Source data of the whole frame
     * @return array<string, TableWindowDescriptorDTO> Windows the tab holds, by table key
     * @throws InvalidFormatException When a descriptor carries no limit or addresses its window
     *     both by anchor and by page index
     */
    private static function readTableWindows(array $data): array
    {
        $windows = [];
        foreach (self::optionalArray($data, self::TABLE_WINDOWS) ?? [] as $tableKey => $descriptor) {
            if (!is_string($tableKey) || !is_array($descriptor)) {
                continue;
            }

            $windows[$tableKey] = TableWindowDescriptorDTO::fromArray($descriptor);
        }

        return $windows;
    }
}

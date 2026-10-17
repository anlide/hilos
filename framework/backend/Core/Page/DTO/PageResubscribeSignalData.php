<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use stdClass;

/** Asks one live browser to repeat its current read subscription on the same socket. */
final class PageResubscribeSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $page Page whose serving leader changed
     * @param array<string, mixed> $params Current subscription params
     * @throws InvalidFormatException When the page is empty or params are not a map
     */
    public function __construct(
        public readonly string $page,
        public readonly array $params,
    ) {
        if ($page === '' || ($params !== [] && array_is_list($params))) {
            throw new InvalidFormatException('Page recovery requires a non-empty page and a params map');
        }
    }

    /** @return array<string, mixed> Page and params on the framework control frame */
    public function toArray(): array
    {
        return ['page' => $this->page, 'params' => (object) $this->params];
    }

    /**
     * @param array<string, mixed> $data Control frame payload
     * @return static Validated recovery request
     * @throws InvalidFormatException When a required field is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        // toArray() preserves an empty map as {} through JSON, and the typed IPC envelope
        // also roundtrips its array directly, before JSON has turned the object into an array.
        if (($data['params'] ?? null) instanceof stdClass) {
            $data['params'] = (array) $data['params'];
        }
        return new static(
            page: self::requireString($data, 'page'),
            params: self::requireArray($data, 'params'),
        );
    }
}

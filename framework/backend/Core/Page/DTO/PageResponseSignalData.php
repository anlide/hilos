<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * PageResponseSignalData - Server-to-client page subscription response.
 *
 * Carries the `{page, payload: {entities?, data?}}` wire form sent to a
 * subscribing connection: the page key lets the frontend drop a late signal
 * for a page it has already left, and the payload is the page scope's entity
 * fragments and plain page-data the frontend normalizer ingests. Tables are
 * not part of this payload yet; they keep flowing through the browser snapshot
 * path until they fold into this signal.
 * Target client id is handled by the WebSocketSignalData wrapper for routing.
 */
final class PageResponseSignalData extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string payload = 'payload';

    /**
     * Creates a page response signal payload.
     *
     * @param string $pageKey Subscribed page key echoed on the signal
     * @param PagePayload $payload Page scope payload (entities and plain data)
     */
    public function __construct(
        public readonly string $pageKey,
        public readonly PagePayload $payload,
    ) {
    }

    /**
     * Converts the signal payload to its wire array.
     *
     * An empty payload is omitted rather than sent as an empty map, the same
     * rule {@see PagePayload::toArray()} applies to its own sections. It has to
     * be: PHP encodes an empty array as the JSON array `[]`, which the client's
     * object-shaped payload schema rejects, and the frame — the acknowledgement
     * a subscribing page waits on — would die at the parse boundary. An absent
     * key reads as "no payload" on both sides.
     *
     * @return array<string, mixed> DTO payload in the `{page, payload?}` wire form
     */
    public function toArray(): array
    {
        $wire = [self::page => $this->pageKey];
        if (!$this->payload->isEmpty()) {
            $wire[self::payload] = $this->payload->toArray();
        }

        return $wire;
    }

    /**
     * Restores the signal payload from its wire array.
     *
     * @param array<string, mixed> $data Source data in the `{page, payload}` wire form
     * @return static Restored DTO instance
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::payload] ?? [];
        if (!is_array($payload)) {
            $payload = [];
        }
        $entities = $payload[PagePayload::entities] ?? [];
        $dataSection = $payload[PagePayload::data] ?? [];
        $lists = $payload[PagePayload::lists] ?? [];
        $tables = $payload[PagePayload::tables] ?? [];

        return new static(
            pageKey: (string)($data[self::page] ?? ''),
            payload: new PagePayload(
                entities: is_array($entities) ? $entities : [],
                data: is_array($dataSection) ? $dataSection : [],
                lists: is_array($lists) ? $lists : [],
                tables: is_array($tables) ? $tables : [],
            ),
        );
    }
}

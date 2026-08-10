<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
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
     * The page key is what the frontend drops a late signal by, so a frame
     * without it is refused. The payload and each of its sections are omitted
     * when empty by {@see self::toArray()} and {@see PagePayload::toArray()},
     * so their absence reads as "nothing in this section" — but a section that
     * arrived as something other than a map is a broken frame, and is no longer
     * quietly read as an empty one.
     *
     * @param array<string, mixed> $data Source data in the `{page, payload}` wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the page key is missing, or the payload or a section is not a map
     */
    public static function fromArray(array $data): static
    {
        $payload = self::optionalArray($data, self::payload) ?? [];

        return new static(
            pageKey: self::requireString($data, self::page),
            payload: new PagePayload(
                entities: self::optionalArray($payload, PagePayload::entities) ?? [],
                data: self::optionalArray($payload, PagePayload::data) ?? [],
                lists: self::optionalArray($payload, PagePayload::lists) ?? [],
                tables: self::optionalArray($payload, PagePayload::tables) ?? [],
            ),
        );
    }
}

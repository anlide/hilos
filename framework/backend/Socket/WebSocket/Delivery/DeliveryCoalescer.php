<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Delivery;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use stdClass;

/** Coalesces known state carriers while retaining event and structural boundaries in order. */
final class DeliveryCoalescer
{
    private const string PAGE_FRAME_PREFIX = 'subscription_page_';
    private const string PAGE_FRAME_ERROR = 'subscription_page_error';

    /** @var array<int, DeliveryFrame> Ordered survivors; replaced positions are removed */
    private array $frames = [];

    /** @var array<string, int> Merge address to its latest survivor in the current segment */
    private array $indices = [];

    /** @var array<string, string> Client-normalized write identity to its typed carrier path */
    private array $writes = [];

    private int $sequence = 0;

    /**
     * A boundary clears indices on both sides without releasing the physical delivery window.
     * The whole incoming frame stays intact when any section cannot be safely merged.
     *
     * @param DeliveryFrame $frame Immutable prepared envelope, shared across recipients
     * @param bool $barrier Caller knows this is a full subscription answer
     * @throws InvalidFormatException When a merged envelope cannot be encoded
     */
    public function append(DeliveryFrame $frame, bool $barrier = false): void
    {
        $key = $barrier ? null : $this->mergeKey($frame);
        $writes = $key === null ? null : $this->frameWrites($frame);
        if ($key === null || $writes === null || $this->hasWriteConflict($writes)) {
            $this->appendBoundary($frame);

            return;
        }

        $previous = isset($this->indices[$key]) ? $this->frames[$this->indices[$key]] : null;
        if ($previous !== null) {
            $merged = $this->mergeFrames($previous, $frame);
            if ($merged === null) {
                $this->appendBoundary($frame);

                return;
            }
            unset($this->frames[$this->indices[$key]]);
            $frame = $merged;
        }
        $position = $this->sequence++;
        $this->frames[$position] = $frame;
        $this->indices[$key] = $position;
        $this->writes = array_replace($this->writes, $writes);
    }

    /** @return bool Whether any text survives coalescing */
    public function hasFrames(): bool
    {
        return $this->frames !== [];
    }

    /** @return list<DeliveryFrame> Survivors in the order of their last occurrences */
    public function drain(): array
    {
        $frames = array_values($this->frames);
        $this->clear();

        return $frames;
    }

    /** Discard the packet and all segment-local indices. */
    public function clear(): void
    {
        $this->frames = [];
        $this->indices = [];
        $this->writes = [];
        $this->sequence = 0;
    }

    /** @param DeliveryFrame $frame Independently observable event or structural transition */
    private function appendBoundary(DeliveryFrame $frame): void
    {
        $this->indices = [];
        $this->writes = [];
        $this->frames[$this->sequence++] = $frame;
    }

    /**
     * @param DeliveryFrame $frame Prepared wire boundary
     * @return ?string Typed merge address, absent for independently observable frames
     */
    private function mergeKey(DeliveryFrame $frame): ?string
    {
        if ($frame->isEvent()) {
            return null;
        }
        $envelope = $frame->getEnvelope();
        if ($envelope === null || !self::hasOnlyKeys($envelope, [
            SignalPayloadConstants::FIELD_TYPE, SignalPayloadConstants::FIELD_DATA, DeliveryFrame::time,
        ])) {
            return null;
        }
        $body = $envelope->{SignalPayloadConstants::FIELD_DATA};
        $type = $frame->getType();
        if (str_starts_with($type, self::PAGE_FRAME_PREFIX) && $type !== self::PAGE_FRAME_ERROR
            && !$body instanceof stdClass
        ) {
            return serialize([$type, null, null]);
        }
        if (!$body instanceof stdClass) {
            return null;
        }
        $page = $body->{SignalPayloadConstants::FIELD_PAGE} ?? null;
        if ($page !== null && !is_string($page)) {
            return null;
        }
        $tableKey = $body->{SignalPayloadConstants::FIELD_TABLE_KEY} ?? null;
        if ($tableKey !== null && !is_string($tableKey)) {
            return null;
        }
        if (str_starts_with($type, self::PAGE_FRAME_PREFIX) && $type !== self::PAGE_FRAME_ERROR) {
            return serialize([$type, $page, $tableKey]);
        }
        if (!is_string($page)) {
            return null;
        }
        if ($type === SignalTypeConstants::PAGE_RESPONSE) {
            return serialize([$type, $page]);
        }
        if (!is_string($tableKey)) {
            return null;
        }
        if ($type === SignalTypeConstants::TABLE_VIEWPORT_COUNT) {
            return serialize([$type, $page, $tableKey]);
        }
        if ($type === SignalTypeConstants::TABLE_PROGRESS) {
            $scope = $body->{TableProgressDTO::scope} ?? null;
            $progressKey = $body->{TableProgressDTO::progressKey} ?? null;
            $rowKey = $body->{TableProgressDTO::rowKey} ?? null;
            if (!is_string($scope) || !is_string($progressKey)
                || ($rowKey !== null && !self::isIdentity($rowKey))
            ) {
                return null;
            }

            return serialize([$type, $page, $tableKey, $scope, $progressKey, $rowKey]);
        }
        if ($type === SignalTypeConstants::TABLE_VIEWPORT_DELTA
            && ($body->{TableViewportDeltaDTO::kind} ?? null) === TableViewportDeltaDTO::KIND_ROW_UPDATED
            && self::isIdentity($body->{TableViewportDeltaDTO::rowKey} ?? null)
        ) {
            return serialize([$type, $page, $tableKey, $body->{TableViewportDeltaDTO::rowKey}]);
        }

        return null;
    }

    /**
     * Validates supported patch shapes and records possible String(id)/String(rowKey) collisions.
     * The master has no entityTypes map: equal ids through different aliases are a boundary.
     *
     * @param DeliveryFrame $frame Candidate mergeable envelope
     * @return ?array<string, string> Normalized identity to typed carrier path, null for unsupported shapes
     */
    private function frameWrites(DeliveryFrame $frame): ?array
    {
        $body = $frame->getEnvelope()->{SignalPayloadConstants::FIELD_DATA};
        $writes = [];
        if ($frame->getType() === SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
            if (!self::hasOnlyKeys($body, [
                TableViewportDeltaDTO::page, TableViewportDeltaDTO::tableKey, TableViewportDeltaDTO::kind,
                TableViewportDeltaDTO::rowKey, TableViewportDeltaDTO::row, TableViewportDeltaDTO::own,
            ]) || (property_exists($body, TableViewportDeltaDTO::own) && !is_bool($body->{TableViewportDeltaDTO::own}))) {
                return null;
            }
            $row = $body->{TableViewportDeltaDTO::row} ?? null;
            if (!$row instanceof stdClass || ($row->{PagePayload::rowKey} ?? null) !== $body->{TableViewportDeltaDTO::rowKey}) {
                return null;
            }
            $path = [$frame->getType(), $body->{TableViewportDeltaDTO::tableKey}];

            return self::collectRowWrites($writes, $body->{TableViewportDeltaDTO::page}, $path, $row, PagePayload::rowKey)
                ? $writes : null;
        }
        if ($frame->getType() === SignalTypeConstants::TABLE_PROGRESS) {
            $rowKey = $body->{TableProgressDTO::rowKey} ?? null;
            if ($rowKey !== null) {
                $writes[serialize([
                    $body->{SignalPayloadConstants::FIELD_PAGE}, 'progress', $body->{SignalPayloadConstants::FIELD_TABLE_KEY},
                    $body->{TableProgressDTO::scope}, $body->{TableProgressDTO::progressKey}, (string) $rowKey,
                ])] = serialize($rowKey);
            }

            return $writes;
        }
        if ($frame->getType() !== SignalTypeConstants::PAGE_RESPONSE) {
            return $writes;
        }
        if (!self::hasOnlyKeys($body, [PageResponseSignalData::page, PageResponseSignalData::payload])) {
            return null;
        }
        $payload = $body->{PageResponseSignalData::payload} ?? new stdClass();
        if (!$payload instanceof stdClass || !self::hasOnlyKeys($payload, [
            PagePayload::entities, PagePayload::data, PagePayload::tables, PagePayload::lists,
        ])) {
            return null;
        }
        $page = $body->{PageResponseSignalData::page};
        foreach (get_object_vars($payload) as $section => $values) {
            if (!$values instanceof stdClass) {
                return null;
            }
            foreach (get_object_vars($values) as $source => $value) {
                // A cross-section collision would synthesize an invalid normalizer payload.
                if (!self::collectWrite($writes, serialize([$page, 'source', $source]), $section)) {
                    return null;
                }
                $path = [SignalTypeConstants::PAGE_RESPONSE, $section, $source];
                if ($section === PagePayload::data) {
                    continue;
                }
                if ($section === PagePayload::entities) {
                    if (!self::isEntity($value) || !self::collectEntityWrite($writes, $page, $path, $value)) {
                        return null;
                    }
                    continue;
                }
                $rowField = $section === PagePayload::tables ? PagePayload::rows : PagePayload::items;
                $keyField = $section === PagePayload::tables ? PagePayload::rowKey : PagePayload::itemKey;
                if (!$value instanceof stdClass || !self::hasOnlyKeys($value, [$rowField])
                    || !is_array($value->{$rowField} ?? null)
                ) {
                    return null;
                }
                $seen = [];
                foreach ($value->{$rowField} as $row) {
                    if (!$row instanceof stdClass || !self::collectRowWrites($writes, $page, $path, $row, $keyField)) {
                        return null;
                    }
                    $identity = serialize($row->{$keyField});
                    if (isset($seen[$identity])) {
                        return null;
                    }
                    $seen[$identity] = true;
                }
            }
        }

        return $writes;
    }

    /**
     * @param array<string, string> $writes Candidate wire-write footprint
     * @return bool Whether moving a survivor could reorder another carrier's write
     */
    private function hasWriteConflict(array $writes): bool
    {
        foreach ($writes as $identity => $path) {
            if (isset($this->writes[$identity]) && $this->writes[$identity] !== $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $writes JSON-boundary identity map being assembled
     * @param string $identity Client-normalized identity
     * @param string $path Typed carrier path
     * @return bool Whether the identity has a single unambiguous carrier
     */
    private static function collectWrite(array &$writes, string $identity, string $path): bool
    {
        if (isset($writes[$identity]) && $writes[$identity] !== $path) {
            return false;
        }
        $writes[$identity] = $path;

        return true;
    }

    /**
     * @param array<string, string> $writes JSON-boundary identity map being assembled
     * @param string $page Scope address
     * @param list<int|string> $path Typed carrier path
     * @param stdClass $entity Entity fragment with a supported scalar id
     * @return bool Whether this write has no alias or typed-id collision
     */
    private static function collectEntityWrite(array &$writes, string $page, array $path, stdClass $entity): bool
    {
        return self::collectWrite(
            $writes,
            serialize([$page, 'entity', (string) $entity->id]),
            serialize([...$path, $entity->id]),
        );
    }

    /**
     * @param array<string, string> $writes JSON-boundary identity map being assembled
     * @param string $page Scope address
     * @param list<int|string> $path Table/list carrier path
     * @param stdClass $row Wire row or item
     * @param string $keyField Existing rowKey/itemKey wire constant
     * @return bool Whether the row and every slot can be merged without hidden entity effects
     */
    private static function collectRowWrites(array &$writes, string $page, array $path, stdClass $row, string $keyField): bool
    {
        $keys = [$keyField, PagePayload::slots];
        if ($keyField === PagePayload::rowKey) {
            $keys[] = PagePayload::staleSources;
        }
        if (!self::hasOnlyKeys($row, $keys)
            || !self::isIdentity($row->{$keyField} ?? null)
            || !($row->{PagePayload::slots} ?? null) instanceof stdClass
        ) {
            return false;
        }
        if (property_exists($row, PagePayload::staleSources)) {
            if (!is_array($row->{PagePayload::staleSources})) {
                return false;
            }
            foreach ($row->{PagePayload::staleSources} as $source) {
                if (!is_string($source)) {
                    return false;
                }
            }
        }
        if (!self::collectWrite(
            $writes,
            serialize([$page, 'row', $path, (string) $row->{$keyField}]),
            serialize($row->{$keyField}),
        )) {
            return false;
        }
        foreach (get_object_vars($row->{PagePayload::slots}) as $slot => $value) {
            if (is_array($value)) {
                foreach ($value as $element) {
                    if ($element instanceof stdClass && isset($element->id)) {
                        return false;
                    }
                }
            }
            if (self::isEntity($value)
                && !self::collectEntityWrite($writes, $page, [...$path, $row->{$keyField}, $slot], $value)
            ) {
                return false;
            }
            // An object with a non-scalar id has normalizer side effects we cannot key safely.
            if ($value instanceof stdClass && isset($value->id) && !self::isIdentity($value->id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param DeliveryFrame $previous Previous survivor of this address
     * @param DeliveryFrame $latest Last occurrence, whose metadata and position survive
     * @return ?DeliveryFrame Merged frame, or null for a structural transition
     * @throws InvalidFormatException When a merged envelope cannot be encoded
     */
    private function mergeFrames(DeliveryFrame $previous, DeliveryFrame $latest): ?DeliveryFrame
    {
        $type = $latest->getType();
        if ($type !== SignalTypeConstants::PAGE_RESPONSE && $type !== SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
            return $latest;
        }
        $before = $previous->getEnvelope()->{SignalPayloadConstants::FIELD_DATA};
        $envelope = $latest->getEnvelope();
        $after = $envelope->{SignalPayloadConstants::FIELD_DATA};
        if ($type === SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
            if (($before->{TableViewportDeltaDTO::own} ?? false) !== ($after->{TableViewportDeltaDTO::own} ?? false)) {
                return null;
            }
            $row = self::mergeRow($before->{TableViewportDeltaDTO::row}, $after->{TableViewportDeltaDTO::row});
            if ($row === null) {
                return null;
            }
            $after->{TableViewportDeltaDTO::row} = $row;
        } else {
            $payload = self::mergePagePayload(
                $before->{PageResponseSignalData::payload} ?? new stdClass(),
                $after->{PageResponseSignalData::payload} ?? new stdClass(),
            );
            if ($payload === null) {
                return null;
            }
            if (get_object_vars($payload) !== []) {
                $after->{PageResponseSignalData::payload} = $payload;
            }
        }

        return DeliveryFrame::fromEnvelope($envelope, urgent: $previous->isUrgent() || $latest->isUrgent());
    }

    /**
     * @param stdClass $before Earlier supported page patch
     * @param stdClass $after Later supported page patch
     * @return ?stdClass All sections merged atomically, absent on any structural transition
     */
    private static function mergePagePayload(stdClass $before, stdClass $after): ?stdClass
    {
        foreach (get_object_vars($after) as $section => $values) {
            if (!property_exists($before, $section)) {
                $before->{$section} = $values;
                continue;
            }
            foreach (get_object_vars($values) as $source => $value) {
                $earlier = $before->{$section}->{$source} ?? null;
                if ($earlier === null || $section === PagePayload::data) {
                    $before->{$section}->{$source} = $value;
                } elseif ($section === PagePayload::entities) {
                    if ($earlier->id !== $value->id) {
                        return null;
                    }
                    $before->{$section}->{$source} = self::mergeFields($earlier, $value);
                } else {
                    $rowField = $section === PagePayload::tables ? PagePayload::rows : PagePayload::items;
                    $keyField = $section === PagePayload::tables ? PagePayload::rowKey : PagePayload::itemKey;
                    $rows = $earlier->{$rowField};
                    $positions = [];
                    foreach ($rows as $position => $row) {
                        $positions[serialize($row->{$keyField})] = $position;
                    }
                    foreach ($value->{$rowField} as $row) {
                        $identity = serialize($row->{$keyField});
                        if (!isset($positions[$identity])) {
                            $positions[$identity] = count($rows);
                            $rows[] = $row;
                            continue;
                        }
                        $merged = self::mergeRow($rows[$positions[$identity]], $row);
                        if ($merged === null) {
                            return null;
                        }
                        $rows[$positions[$identity]] = $merged;
                    }
                    $earlier->{$rowField} = $rows;
                }
            }
        }

        return $before;
    }

    /**
     * Slots are a full replacement map. Only an identical slot set allows field merging;
     * nested values inside an entity field are replaced, never recursively patched.
     *
     * @param stdClass $before Earlier validated row/item
     * @param stdClass $after Later validated row/item
     * @return ?stdClass Later row with accumulated entity fields, absent on a structural transition
     */
    private static function mergeRow(stdClass $before, stdClass $after): ?stdClass
    {
        $oldSlots = get_object_vars($before->{PagePayload::slots});
        $newSlots = get_object_vars($after->{PagePayload::slots});
        if (array_diff_key($oldSlots, $newSlots) !== [] || array_diff_key($newSlots, $oldSlots) !== []
            || ($before->{PagePayload::staleSources} ?? null) !== ($after->{PagePayload::staleSources} ?? null)
        ) {
            return null;
        }
        foreach ($newSlots as $slot => $value) {
            $old = $oldSlots[$slot];
            if (($old === null) !== ($value === null) || self::isEntity($old) !== self::isEntity($value)) {
                return null;
            }
            if (self::isEntity($value)) {
                if ($old->id !== $value->id) {
                    return null;
                }
                $after->{PagePayload::slots}->{$slot} = self::mergeFields($old, $value);
            }
        }

        return $after;
    }

    /**
     * @param stdClass $before Earlier entity fragment
     * @param stdClass $after Later entity fragment of the same typed id
     * @return stdClass Detached shallow field merge, including explicit null and whole nested values
     */
    private static function mergeFields(stdClass $before, stdClass $after): stdClass
    {
        return (object) array_replace(get_object_vars($before), get_object_vars($after));
    }

    /**
     * @param mixed $value Wire value
     * @return bool Whether the normalizer treats this as an entity with a supported scalar id
     */
    private static function isEntity(mixed $value): bool
    {
        return $value instanceof stdClass && self::isIdentity($value->id ?? null);
    }

    /**
     * @param mixed $value Wire identity
     * @return bool Whether the identity retains its type without guessing a client conversion
     */
    private static function isIdentity(mixed $value): bool
    {
        return is_string($value) || is_int($value);
    }

    /**
     * @param stdClass $value Wire map
     * @param list<string> $keys Supported production wire keys
     * @return bool Whether no unknown shape would be lost or misinterpreted during merging
     */
    private static function hasOnlyKeys(stdClass $value, array $keys): bool
    {
        return array_diff(array_keys(get_object_vars($value)), $keys) === [];
    }
}

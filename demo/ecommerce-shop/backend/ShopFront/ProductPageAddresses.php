<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\ShopFront;

use Closure;
use Demo\EcommerceShop\Database\Actions\CatalogSlug;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Entity\Item\ProductSlug as EntityProductSlug;
use Demo\EcommerceShop\Hilos;
use Generator;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\ConnectionReadBoundary;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\HilosException;
use Hilos\Utils\Logger;
use Hilos\WiringRefusal;
use Throwable;

/**
 * Selects possible recipients of product facts and shares PRIMARY failures across one browser flush.
 * The page confirms its address and visibility before answering.
 */
final class ProductPageAddresses
{
    private const int UTF8_MAX_BYTES_PER_CHARACTER = 4;
    private const int BATCH_KEYS = 32;
    private const int BATCH_KEY_SQL_BYTES = 8192;
    private const int MYSQL_SERVER_PACKET_TOO_LARGE = 1153;
    private const int MYSQL_CLIENT_PACKET_TOO_LARGE = 2020;

    private readonly ConnectionReadBoundary $primary;

    /** Starts a fresh connection boundary for this flush. */
    public function __construct()
    {
        $this->primary = new ConnectionReadBoundary();
    }

    /** @return bool Whether a read already established that PRIMARY is unavailable */
    public function primaryUnavailable(): bool
    {
        return $this->primary->failure() !== null;
    }

    /** @throws DatabaseException When a read already established that PRIMARY is unavailable */
    public function assertPrimaryAvailable(): void
    {
        $this->primary->assertAvailable();
    }

    /**
     * @template T
     * @param Closure(): T $read Model or browser work that can also use loaded data or RT
     * @return T Result of the work
     * @throws Throwable Whatever the work raises
     */
    public function observePrimary(Closure $read): mixed
    {
        return Database::withSqlReadBoundary(
            Database::getDatabase(DatabaseNames::PRIMARY)->connectionIndex,
            $this->primary,
            fn (): mixed => $this->primary->observe($read),
        );
    }

    /**
     * @template TResult
     * @param Closure(): TResult $read Synchronous read from PRIMARY
     * @return TResult The confirmed result of the read
     * @throws HilosException When the read fails or a previous read lost the connection
     */
    public function readPrimary(Closure $read): mixed
    {
        $this->assertPrimaryAvailable();
        try {
            return $this->observePrimary($read);
        } catch (HilosException $failure) {
            if ($this->primary->failure() === $failure) {
                Logger::error('Product page source unavailable: ' . $failure->getMessage());
            }
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('The product page source could not be read', previous: $failure);
        }
    }

    /**
     * @param string $slug Exact address supplied by the browser
     * @return bool Whether it can fit the stored slug column
     */
    public static function isUsableSlug(string $slug): bool
    {
        return $slug !== '' && strlen($slug) <= CatalogSlug::MAX_LENGTH * self::UTF8_MAX_BYTES_PER_CHARACTER
            && mb_strlen($slug, 'UTF-8') <= CatalogSlug::MAX_LENGTH;
    }

    /**
     * @param array<int|string, string> $slugs Different exact routes, keyed by their slug
     * @param ?list<int> $changedIds Product keys of an addressed fact, or null for a fact covering every route
     * @param ?Closure(list<int|string>, Throwable): void $failed Receives the keys of any unread part and the reason
     * @return array<int|string, int> Eligible exact routes mapped to product IDs
     */
    public function matchingProducts(array $slugs, ?array $changedIds, ?Closure $failed = null): array
    {
        $slugs = array_filter($slugs, self::isUsableSlug(...));
        if ($slugs === []) {
            return [];
        }

        return $changedIds === null
            ? $this->matchSlugs($slugs, $failed)
            : $this->matchChangedProducts($slugs, $changedIds, $failed);
    }

    /**
     * Independently reads bounded parts. Only an explicit packet-size refusal warrants
     * reading smaller parts of a failed query. A wiring refusal stops this source.
     * A lost connection stops every new PRIMARY read in the flush.
     *
     * @template TKey of int|string
     * @template TResult
     * @param list<TKey> $keys Different values going into an ORM lookup
     * @param Closure(list<TKey>): TResult $read Read of one batch
     * @param ?Closure(list<TKey>, Throwable): void $failed Receives the keys of any unread part and the reason
     * @return Generator<int, TResult> Successful batch results
     */
    public function readBatches(array $keys, Closure $read, ?Closure $failed = null): Generator
    {
        $refusal = null;
        foreach (self::batches($keys) as $batch) {
            yield from $this->readBatch($batch, $read, $failed, $refusal);
        }
    }

    /**
     * @param array<int|string, string> $slugs Exact routes to read
     * @param ?Closure(list<string>, Throwable): void $failed Receives unread addresses and the reason
     * @return array<int|string, int> Routes mapped to their current or former product IDs
     */
    private function matchSlugs(array $slugs, ?Closure $failed): array
    {
        $matched = [];
        $historyRefusal = null;
        $read = static function (array $batch) use (&$matched, &$historyRefusal, $failed): array {
            return Database::readSnapshot(DatabaseNames::PRIMARY, static function () use ($batch, &$matched, &$historyRefusal, $failed): array {
                $remaining = array_fill_keys($batch, true);
                foreach (Hilos::$db->products->storedProducts(EntityProduct::slug, ...$batch) as $product) {
                    if (isset($remaining[$product->slug])) {
                        $matched[$product->slug] = $product->id;
                        unset($remaining[$product->slug]);
                    }
                }
                if ($remaining === []) {
                    return [];
                }
                $unresolved = array_map(strval(...), array_keys($remaining));
                if ($historyRefusal === null) {
                    try {
                        return array_intersect_key(
                            Hilos::$db->productSlugs->storedAddresses(EntityProductSlug::slug, ...$unresolved),
                            $remaining,
                        );
                    } catch (WiringRefusal $failure) {
                        $historyRefusal = $failure;
                    }
                }

                $failed?->__invoke($unresolved, $historyRefusal);
                return [];
            });
        };
        $unreadBatch = $failed === null ? null : static function (array $batch, Throwable $failure) use (&$matched, $failed): void {
            $unresolved = array_values(array_filter($batch, static fn (string $slug): bool => !isset($matched[$slug])));
            if ($unresolved !== []) {
                $failed($unresolved, $failure);
            }
        };
        foreach ($this->readBatches(array_values($slugs), $read, $unreadBatch) as $former) {
            $matched += $former;
        }

        return $matched;
    }

    /**
     * @param array<int|string, string> $slugs Exact routes to consider
     * @param list<int> $changedIds Product keys named by the incoming facts
     * @param ?Closure(list<int|string>, Throwable): void $failed Receives unread product keys or exact addresses and the reason
     * @return array<int|string, int> Routes that name one of the changed products
     */
    private function matchChangedProducts(array $slugs, array $changedIds, ?Closure $failed): array
    {
        $matched = [];
        $remaining = $slugs;
        $productsRefused = false;
        $readCurrent = static fn (array $ids): array => iterator_to_array(Hilos::$db->products->storedProducts(EntityProduct::id, ...$ids));
        $currentFailed = static function (array $ids, Throwable $failure) use (&$productsRefused, $failed): void {
            $productsRefused = $failure instanceof WiringRefusal;
            $failed?->__invoke($ids, $failure);
        };
        foreach ($this->readBatches($changedIds, $readCurrent, $currentFailed) as $products) {
            foreach ($products as $product) {
                if (isset($remaining[$product->slug])) {
                    $matched[$product->slug] = $product->id;
                    unset($remaining[$product->slug]);
                }
            }
        }
        if ($remaining === [] || $productsRefused || $this->primaryUnavailable()) {
            return $matched;
        }

        $history = [];
        $readHistory = static fn (array $ids): array => Hilos::$db->productSlugs->storedAddresses(EntityProductSlug::product_id, ...$ids);
        foreach ($this->readBatches($changedIds, $readHistory, $failed) as $former) {
            $history += array_intersect_key($former, $remaining);
        }
        if ($this->primaryUnavailable()) {
            return $matched;
        }

        $formerSlugs = array_intersect_key($remaining, $history);
        $changed = array_fill_keys($changedIds, true);
        $readOverlap = static function (array $batch) use ($history, $changed): array {
            $candidates = array_intersect_key($history, array_fill_keys($batch, true));
            foreach (Hilos::$db->products->storedProducts(EntityProduct::slug, ...$batch) as $product) {
                if (isset($candidates[$product->slug])) {
                    unset($candidates[$product->slug]);
                    if (isset($changed[$product->id])) {
                        $candidates[$product->slug] = $product->id;
                    }
                }
            }

            return $candidates;
        };
        foreach ($this->readBatches(array_values($formerSlugs), $readOverlap, $failed) as $confirmed) {
            $matched += $confirmed;
        }

        return $matched;
    }

    /**
     * @template TKey of int|string
     * @param list<TKey> $keys Different lookup values
     * @return list<list<TKey>> Bounds on both key count and the encoded SQL contributed by the values
     */
    private static function batches(array $keys): array
    {
        $batches = [];
        $batch = [];
        $bytes = 0;
        foreach ($keys as $key) {
            // Escaping can double a byte. Allow four further bytes for quotes and the separator.
            $keyBytes = 2 * strlen((string) $key) + 4;
            if (count($batch) === self::BATCH_KEYS || $bytes + $keyBytes > self::BATCH_KEY_SQL_BYTES) {
                $batches[] = $batch;
                $batch = [];
                $bytes = 0;
            }
            $batch[] = $key;
            $bytes += $keyBytes;
        }
        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * @template TKey of int|string
     * @template TResult
     * @param list<TKey> $keys Keys in this part
     * @param Closure(list<TKey>): TResult $read Read of one batch
     * @param ?Closure(list<TKey>, Throwable): void $failed Receives the keys of any unread part and the reason
     * @param ?WiringRefusal $refusal First refusal of the source shared by these batches
     * @return Generator<int, TResult> Successful results
     */
    private function readBatch(array $keys, Closure $read, ?Closure $failed, ?WiringRefusal &$refusal): Generator
    {
        $primaryFailure = $this->primary->failure();
        if ($primaryFailure !== null) {
            $failed?->__invoke($keys, $primaryFailure);
            return;
        }
        if ($refusal !== null) {
            $failed?->__invoke($keys, $refusal);
            return;
        }

        try {
            $result = $this->readPrimary(static fn (): mixed => $read($keys));
        } catch (WiringRefusal $failure) {
            $refusal = $failure;
            $failed?->__invoke($keys, $failure);
            return;
        } catch (Throwable $failure) {
            if ($this->primaryUnavailable()) {
                $failed?->__invoke($keys, $failure);
                return;
            }
            if ($failure instanceof DatabaseException && count($keys) > 1
                && in_array($failure->getCode(), [self::MYSQL_SERVER_PACKET_TOO_LARGE, self::MYSQL_CLIENT_PACKET_TOO_LARGE], true)
            ) {
                $middle = count($keys) >> 1;
                yield from $this->readBatch(array_slice($keys, 0, $middle), $read, $failed, $refusal);
                yield from $this->readBatch(array_slice($keys, $middle), $read, $failed, $refusal);
                return;
            }

            $failed?->__invoke($keys, $failure);
            Logger::error('Product page batch read failed: ' . $failure->getMessage());
            return;
        }

        yield $result;
    }
}

<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Runtime\State\Item;

use Demo\EcommerceShop\Runtime\Row\ProductViewerCountRowCodec;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Item\RtState;
use LogicException;

/** A positive live viewer count for the product currently served at a storefront address. */
final class ProductViewerCount extends RtState
{
    public const string slug = 'slug';
    public const string productId = 'productId';
    public const string viewerCount = 'viewerCount';

    /** Fixed descriptor schema in export order. */
    private const array FIELD_DEFAULTS = [
        self::slug => '',
        self::productId => 0,
        self::viewerCount => 0,
    ];

    private(set) string $slug {
        get => $this->rowValues()[self::slug]->toNative();
        set {
            $this->setRowValue(self::slug, RtRowValues::fromNative($value));
        }
    }

    private(set) int $productId {
        get => $this->rowValues()[self::productId];
        set {
            $this->setRowValue(self::productId, $value);
        }
    }

    private(set) int $viewerCount {
        get => $this->rowValues()[self::viewerCount];
        set {
            $this->setRowValue(self::viewerCount, $value);
        }
    }

    /**
     * @param string $slug Current storefront address
     * @param int $productId Product at the address
     * @param int $viewerCount Number of distinct viewers
     * @return static New runtime row
     * @throws InvalidFormatException When the address is empty or either integer is not positive
     */
    public static function create(string $slug, int $productId, int $viewerCount): static
    {
        if ($slug === '') {
            throw new InvalidFormatException('Product viewer count needs a slug');
        }

        $row = new static();
        $row->slug = $slug;
        $row->productId = self::positive($productId, self::productId);
        $row->viewerCount = self::positive($viewerCount, self::viewerCount);
        $row->markRtSyncBaseline();

        return $row;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated runtime row
     * @throws InvalidFormatException When a required field is missing, malformed or outside its domain
     */
    public static function fromRow(array $row): static
    {
        return static::create(
            self::requireString($row, self::slug),
            self::requireInt($row, self::productId),
            self::requireInt($row, self::viewerCount),
        );
    }

    /** @return string The collection carrying storefront viewer counts */
    public static function getRtCollectionKey(): string
    {
        return EcommerceShopRtContext::productViewerCounts;
    }

    /** @return string Current storefront slug */
    public function getId(): string
    {
        return $this->slug;
    }

    /**
     * @param array<string, mixed> $diff Partial row update
     * @throws InvalidFormatException When the patched count is malformed or is not positive
     */
    public function applyDiff(array $diff): void
    {
        $this->viewerCount = self::positive(self::patchInt($diff, self::viewerCount, $this->viewerCount), self::viewerCount);
    }

    /** @return array<string, mixed> Complete scalar row */
    public function toArray(): array
    {
        return [
            self::slug => $this->slug,
            self::productId => $this->productId,
            self::viewerCount => $this->viewerCount,
        ];
    }

    /** @return RtRowCodec Segmented row codec */
    public function rowCodec(): RtRowCodec
    {
        return new ProductViewerCountRowCodec();
    }

    /**
     * @param RtRowReader $in Segmented full row
     * @return Generator<int, RtWork, int, static> Validated candidate
     * @throws InvalidFormatException When a required field is missing, malformed or outside its domain
     */
    public static function fromValues(RtRowReader $in): Generator
    {
        $slug = yield from $in->field(self::slug);
        if ($slug === null || $slug->reader()->kind() !== RtRowKind::Text || (yield from $slug->reader()->equals(''))) {
            throw new InvalidFormatException('Product viewer count needs a slug');
        }
        $productId = yield from self::readPositive($in, self::productId);
        $viewerCount = yield from self::readPositive($in, self::viewerCount);
        if ($productId === null || $viewerCount === null) {
            throw new InvalidFormatException('Product viewer count needs a product id and a viewer count');
        }

        $row = new static();
        $row->replaceRowValues([
            self::slug => $slug->reader()->retain(RtRowRole::Candidate),
            self::productId => $productId,
            self::viewerCount => $viewerCount,
        ]);

        return $row;
    }

    /**
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Atomic count patch
     * @throws InvalidFormatException When the patched count is malformed or is not positive
     */
    public function patchValues(RtRowReader $diff): Generator
    {
        $count = yield from self::readPositive($diff, self::viewerCount);
        if ($count !== null) {
            $this->viewerCount = $count;
        }
    }

    /**
     * @param RtRowWriter $out Unpublished destination
     * @return Generator<int, RtWork, int, void> Bounded row export
     * @throws InvalidFormatException When stored values are malformed
     * @throws LogicException When the output is closed
     */
    public function exportValues(RtRowWriter $out): Generator
    {
        return $this->frozenValues()->export($out);
    }

    /** @return array<string, mixed> Fixed descriptor schema and export order */
    protected function rowFieldDefaults(): array
    {
        return self::FIELD_DEFAULTS;
    }

    /**
     * @param RtRowReader $in Runtime row or diff to read
     * @param string $key Integer field
     * @return Generator<int, RtWork, int, ?int> Positive integer, or null for an absent field
     * @throws InvalidFormatException When a carried field is malformed or is not positive
     */
    private static function readPositive(RtRowReader $in, string $key): Generator
    {
        $field = yield from $in->field($key);
        if ($field === null) {
            return null;
        }

        return self::positive(yield from $field->reader()->scalar(), $key);
    }

    /**
     * @param mixed $value Value to validate
     * @param string $key Field being validated
     * @return int Positive field value
     * @throws InvalidFormatException When the value is not a positive integer
     */
    private static function positive(mixed $value, string $key): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidFormatException("Runtime row requires a positive integer under {$key}");
        }

        return $value;
    }
}

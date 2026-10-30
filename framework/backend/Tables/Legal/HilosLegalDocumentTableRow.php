<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** Legal administration table row and its wire representation. */
final class HilosLegalDocumentTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string declared = 'declared';
    public const string revision = 'revision';
    public const string covered = 'covered';
    public const string window = 'window';
    public const string lapsed = 'lapsed';
    public const string documentLabel = 'documentLabel';
    public const string publishedOn = 'publishedOn';

    /**
     * @param string $rowKey Stable row identity
     * @param bool $declared Declared
     * @param ?array<string, mixed> $revision Declared revision metadata
     * @param int $covered Covered
     * @param int $window Window
     * @param int $lapsed Lapsed
     * @param string $documentLabel Catalog label or historical document key
     * @param ?string $publishedOn Current revision's calendar publication date, YYYY-MM-DD
     */
    public function __construct(
        public string $rowKey,
        public bool $declared,
        public ?array $revision,
        public int $covered,
        public int $window,
        public int $lapsed,
        public string $documentLabel,
        public ?string $publishedOn,
    ) {
    }

    /** @return string Stable row identity */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Identity field in the wire payload */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Serialized table row */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::declared => $this->declared,
            self::revision => $this->revision,
            self::covered => $this->covered,
            self::window => $this->window,
            self::lapsed => $this->lapsed,
            self::documentLabel => $this->documentLabel,
            self::publishedOn => $this->publishedOn,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire row payload
     * @return static Reconstructed row
     * @throws InvalidFormatException When a field is absent or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            declared: self::requireBool($data, self::declared),
            revision: self::optionalArray($data, self::revision),
            covered: self::requireInt($data, self::covered),
            window: self::requireInt($data, self::window),
            lapsed: self::requireInt($data, self::lapsed),
            documentLabel: self::requireString($data, self::documentLabel),
            publishedOn: self::optionalString($data, self::publishedOn),
        );
    }
}

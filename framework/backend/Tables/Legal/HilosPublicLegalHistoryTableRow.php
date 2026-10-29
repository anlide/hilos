<?php

declare(strict_types=1);

namespace Hilos\Tables\Legal;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** Published revision metadata, without personal acceptance records or aggregate counts. */
final class HilosPublicLegalHistoryTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string revisionId = 'revisionId';
    public const string publishedOn = 'publishedOn';
    public const string effectiveOn = 'effectiveOn';
    public const string significance = 'significance';
    public const string setVersion = 'setVersion';
    public const string deviationCount = 'deviationCount';
    public const string origin = 'origin';
    public const string previousSetVersion = 'previousSetVersion';

    /**
     * @param string $rowKey Revision identity in the table
     * @param string $revisionId Published revision identifier
     * @param string $publishedOn Publication date, YYYY-MM-DD
     * @param string $effectiveOn Acceptance deadline, YYYY-MM-DD
     * @param string $significance Editorial or substantial change
     * @param int $setVersion Adopted standard set version
     * @param int $deviationCount Project deviations in this revision
     * @param string $origin First revision, project change or standard adoption
     * @param ?int $previousSetVersion Previous declaration's standard set, absent for the first
     */
    public function __construct(
        public string $rowKey,
        public string $revisionId,
        public string $publishedOn,
        public string $effectiveOn,
        public string $significance,
        public int $setVersion,
        public int $deviationCount,
        public string $origin,
        public ?int $previousSetVersion,
    ) {
    }

    /** @return string Published revision identity */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /** @return string Identity field of the wire row */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Public metadata row */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::revisionId => $this->revisionId,
            self::publishedOn => $this->publishedOn,
            self::effectiveOn => $this->effectiveOn,
            self::significance => $this->significance,
            self::setVersion => $this->setVersion,
            self::deviationCount => $this->deviationCount,
            self::origin => $this->origin,
            self::previousSetVersion => $this->previousSetVersion,
        ];
    }

    /**
     * @param array<string, mixed> $data Public metadata payload
     * @return static Reconstructed typed row
     * @throws InvalidFormatException When a field is absent or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            revisionId: self::requireString($data, self::revisionId),
            publishedOn: self::requireString($data, self::publishedOn),
            effectiveOn: self::requireString($data, self::effectiveOn),
            significance: self::requireString($data, self::significance),
            setVersion: self::requireInt($data, self::setVersion),
            deviationCount: self::requireInt($data, self::deviationCount),
            origin: self::requireString($data, self::origin),
            previousSetVersion: self::optionalInt($data, self::previousSetVersion),
        );
    }
}

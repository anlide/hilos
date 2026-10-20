<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

use Hilos\Database\DatabaseException;
use JsonException;
use stdClass;

/** Version-one provenance for a generated SQL pair, independent of today's stub shape. */
final readonly class FrameworkMigrationOrigin
{
    public const int FORMAT = 1;
    public const string PREFIX = '-- hilos-framework: ';
    public const string ADOPT = 'adopt';
    public const string INSTALL = 'install';
    public const string CHANGE = 'change';
    public const string BASELINE = 'baseline';

    private const string HASH_ALGORITHM = 'sha256';
    private const string ORIGIN_LINE_PATTERN = '/^[\t ]*--[\t ]*hilos-framework:/m';

    /** @var list<string> Sorted, unique schema units */
    public array $units;

    /** @var list<FrameworkMigrationPart> Sorted, unique covered part references */
    public array $parts;

    /**
     * @param string $kind One of the declared provenance kinds
     * @param list<string> $units Exact unit set represented by the pair
     * @param list<FrameworkMigrationPart> $parts Exact covered set, never a timestamp watermark
     * @param string $upSha256 Digest of the canonical up body without its origin line
     * @param string $downSha256 Digest of the canonical down body without its origin line
     * @throws DatabaseException When the kind, unit set, covered set or body digests are invalid
     */
    public function __construct(
        public string $kind,
        array $units,
        array $parts,
        public string $upSha256,
        public string $downSha256,
    ) {
        if (!in_array($kind, [self::ADOPT, self::INSTALL, self::CHANGE, self::BASELINE], true)) {
            throw new DatabaseException("Unknown framework migration origin kind {$kind}");
        }
        FrameworkMigrationPart::assertHash($upSha256);
        FrameworkMigrationPart::assertHash($downSha256);
        $unitSet = [];
        foreach ($units as $unit) {
            if (!is_string($unit) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $unit) !== 1 || isset($unitSet[$unit])) {
                throw new DatabaseException('Invalid or duplicate framework origin unit');
            }
            $unitSet[$unit] = true;
        }
        if ($unitSet === []) {
            throw new DatabaseException('A framework origin must name at least one unit');
        }
        $partSet = [];
        foreach ($parts as $part) {
            if (!$part instanceof FrameworkMigrationPart || !isset($unitSet[$part->unit]) || isset($partSet[$part->key()])) {
                throw new DatabaseException('Invalid, duplicate or out-of-unit framework origin part');
            }
            $partSet[$part->key()] = $part;
        }
        if ($kind === self::CHANGE && (count($partSet) !== 1 || count($unitSet) !== 1)) {
            throw new DatabaseException('A change origin must name exactly one unit and one part');
        }
        if ($kind === self::ADOPT && $partSet !== []) {
            throw new DatabaseException('A genesis adoption cannot cover change parts');
        }
        ksort($unitSet, SORT_STRING);
        ksort($partSet, SORT_STRING);
        $this->units = array_keys($unitSet);
        $this->parts = array_values($partSet);
    }

    /**
     * UTF-8 without a BOM; CRLF and bare CR become LF. All other bytes, including the final LF, are preserved.
     *
     * @param string $sql SQL text from source or a generated file
     * @return string Canonical SQL text
     * @throws DatabaseException When text is not UTF-8 or starts with a BOM
     */
    public static function canonicalSql(string $sql): string
    {
        if (preg_match('//u', $sql) !== 1 || str_starts_with($sql, "\xEF\xBB\xBF")) {
            throw new DatabaseException('Framework SQL must be UTF-8 without a BOM');
        }

        return str_replace(["\r\n", "\r"], "\n", $sql);
    }

    /**
     * Shared by source readers, pair parsing and future writers.
     *
     * @param string $body SQL without an origin line
     * @return string Canonical body digest
     * @throws DatabaseException When text is invalid or contains an origin line
     */
    public static function bodyHash(string $body): string
    {
        $body = self::canonicalSql($body);
        if (preg_match(self::ORIGIN_LINE_PATTERN, $body) === 1) {
            throw new DatabaseException('A framework SQL body cannot contain another origin line');
        }

        return hash(self::HASH_ALGORITHM, $body);
    }

    /**
     * @param string $kind Provenance kind
     * @param list<string> $units Exact unit set
     * @param list<FrameworkMigrationPart> $parts Exact covered part set
     * @param string $upBody Up SQL without a header
     * @param string $downBody Down SQL without a header
     * @return self Provenance ready for the common header writer
     * @throws DatabaseException When provenance or either body is invalid
     */
    public static function forBodies(string $kind, array $units, array $parts, string $upBody, string $downBody): self
    {
        return new self($kind, $units, $parts, self::bodyHash($upBody), self::bodyHash($downBody));
    }

    /**
     * @param string $upSql Generated up file, starting with its origin line
     * @param string $downSql Generated down file, starting with the same origin line
     * @return self Parsed provenance with verified body hashes
     * @throws DatabaseException When format, headers, sets or body hashes disagree
     */
    public static function parsePair(string $upSql, string $downSql): self
    {
        $upSql = self::canonicalSql($upSql);
        $downSql = self::canonicalSql($downSql);
        $upEnd = strpos($upSql, "\n");
        $downEnd = strpos($downSql, "\n");
        if (
            $upEnd === false || $downEnd === false
            || !str_starts_with($upSql, self::PREFIX) || !str_starts_with($downSql, self::PREFIX)
            || substr($upSql, 0, $upEnd) !== substr($downSql, 0, $downEnd)
        ) {
            throw new DatabaseException('Framework migration up/down origin headers must match on the first line');
        }
        try {
            $data = json_decode(substr($upSql, strlen(self::PREFIX), $upEnd - strlen(self::PREFIX)), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DatabaseException('Invalid framework migration origin JSON', previous: $exception);
        }
        if (
            !$data instanceof stdClass || ($data->format ?? null) !== self::FORMAT
            || !is_string($data->kind ?? null)
            || !is_array($data->units ?? null) || !is_array($data->parts ?? null)
            || !is_string($data->upSha256 ?? null) || !is_string($data->downSha256 ?? null)
            || count(get_object_vars($data)) !== 6
        ) {
            throw new DatabaseException('Unsupported or malformed framework migration origin format');
        }
        $parts = [];
        foreach ($data->parts as $part) {
            if (
                !$part instanceof stdClass || count(get_object_vars($part)) !== 4
                || !is_string($part->changeId ?? null) || !is_string($part->unit ?? null)
                || !is_string($part->upSha256 ?? null) || !is_string($part->downSha256 ?? null)
            ) {
                throw new DatabaseException('Malformed framework migration origin part');
            }
            $parts[] = new FrameworkMigrationPart($part->changeId, $part->unit, $part->upSha256, $part->downSha256);
        }
        $origin = new self($data->kind, $data->units, $parts, $data->upSha256, $data->downSha256);
        if (
            self::bodyHash(substr($upSql, $upEnd + 1)) !== $origin->upSha256
            || self::bodyHash(substr($downSql, $downEnd + 1)) !== $origin->downSha256
        ) {
            throw new DatabaseException('Framework migration body hash disagrees with its origin');
        }

        return $origin;
    }

    /**
     * @return string Canonical first line shared by both generated directions, without its LF
     * @throws DatabaseException When provenance cannot be encoded as JSON
     */
    public function header(): string
    {
        try {
            return self::PREFIX . json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new DatabaseException('Cannot encode framework migration origin', previous: $exception);
        }
    }

    /** @return array<string, mixed> Version-one JSON boundary shape */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'kind' => $this->kind,
            'units' => $this->units,
            'parts' => array_map(static fn (FrameworkMigrationPart $part): array => $part->toArray(), $this->parts),
            'upSha256' => $this->upSha256,
            'downSha256' => $this->downSha256,
        ];
    }
}

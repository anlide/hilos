<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Database\DatabaseException;

/** Immutable part identity and digests; source parts additionally carry their SQL and dependency keys. */
final readonly class FrameworkMigrationPart
{
    private const string KEY_SEPARATOR = '/';

    /**
     * @param string $changeId UTC change group identifier
     * @param string $unit Stable schema unit key
     * @param string $upSha256 Canonical up source digest
     * @param string $downSha256 Canonical down source digest
     * @param list<string> $requires Explicit dependency keys made by keyOf()
     * @param ?string $upSql Canonical source SQL, absent on an origin reference
     * @param ?string $downSql Canonical rollback SQL, absent on an origin reference
     * @param ?string $afterStubUpSha256 Up stub digest after this part, null for bootstrap ownership or a reference
     * @param ?string $afterStubDownSha256 Down stub digest after this part, null for bootstrap ownership or a reference
     * @throws DatabaseException When identity, digests, source pair or dependency keys are malformed
     */
    public function __construct(
        public string $changeId,
        public string $unit,
        public string $upSha256,
        public string $downSha256,
        public array $requires = [],
        public ?string $upSql = null,
        public ?string $downSql = null,
        public ?string $afterStubUpSha256 = null,
        public ?string $afterStubDownSha256 = null,
    ) {
        self::keyOf($changeId, $unit);
        self::assertHash($upSha256);
        self::assertHash($downSha256);
        foreach ([$afterStubUpSha256, $afterStubDownSha256] as $hash) {
            if ($hash !== null) {
                self::assertHash($hash);
            }
        }
        if (($upSql === null) !== ($downSql === null)) {
            throw new DatabaseException('A framework migration source needs both SQL directions');
        }
        if ($upSql !== null) {
            if (
                FrameworkMigrationOrigin::bodyHash($upSql) !== $upSha256
                || FrameworkMigrationOrigin::bodyHash($downSql) !== $downSha256
            ) {
                throw new DatabaseException('Framework migration part digests disagree with its source');
            }
        }
        $seen = [];
        foreach ($requires as $key) {
            if (!is_string($key)) {
                throw new DatabaseException('A framework part dependency must be a part key');
            }
            $identity = explode(self::KEY_SEPARATOR, $key);
            if (count($identity) !== 2 || self::keyOf(...$identity) !== $key || isset($seen[$key])) {
                throw new DatabaseException("Invalid or duplicate framework part dependency {$key}");
            }
            $seen[$key] = true;
        }
    }

    /**
     * @param string $changeId UTC group id, with microseconds, ticket and slug
     * @param string $unit Stable schema unit key
     * @return string Unambiguous part key, also the deterministic tie breaker
     * @throws DatabaseException When either identity component is malformed
     */
    public static function keyOf(string $changeId, string $unit): string
    {
        if (
            preg_match('/\A([0-9]{20})_HIL-[1-9][0-9]*_[a-z][a-z0-9_-]*\z/D', $changeId, $match) !== 1
            || preg_match('/\A[a-z][a-z0-9_]*\z/D', $unit) !== 1
        ) {
            throw new DatabaseException("Invalid framework migration part identity {$changeId}/{$unit}");
        }
        $instant = DateTimeImmutable::createFromFormat('!YmdHisu', $match[1], new DateTimeZone('UTC'));
        if ($instant === false || $instant->format('YmdHisu') !== $match[1]) {
            throw new DatabaseException("Invalid UTC framework change id {$changeId}");
        }

        return $changeId . self::KEY_SEPARATOR . $unit;
    }

    /**
     * @param string $hash Untrusted digest text
     * @throws DatabaseException When the digest is not lowercase SHA-256 hex
     */
    public static function assertHash(string $hash): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new DatabaseException('A framework migration digest must be lowercase SHA-256 hex');
        }
    }

    /** @return string Exact part identity */
    public function key(): string
    {
        return $this->changeId . self::KEY_SEPARATOR . $this->unit;
    }

    /** @return array{changeId: string, unit: string, upSha256: string, downSha256: string} Origin boundary record */
    public function toArray(): array
    {
        return [
            'changeId' => $this->changeId,
            'unit' => $this->unit,
            'upSha256' => $this->upSha256,
            'downSha256' => $this->downSha256,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\DaemonSection\DTO;

use Hilos\BaseDTO;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\DaemonSection\DaemonCronPicture;
use Hilos\DaemonSection\DaemonCronRulePicture;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\DaemonSection\NodeEnvironmentSummary;
use Hilos\DaemonSection\NodeEnvironmentFingerprint;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvSource;

/** Complete node picture sent from the node agent to the collector. */
final class DaemonNodePictureSignalData extends BaseDTO implements SignalDataInterface
{
    public const string nodeId = 'nodeId';
    public const string role = 'role';
    public const string sampledAt = 'sampledAt';
    public const string processes = 'processes';
    public const string cron = 'cron';
    public const string environment = 'environment';
    public const string standing = 'standing';
    public const string http = 'http';
    public const string catalogKeys = 'catalogKeys';
    public const string missingRequired = 'missingRequired';
    public const string fromExample = 'fromExample';
    public const string drifted = 'drifted';
    public const string orphans = 'orphans';
    public const string fingerprints = 'fingerprints';
    public const string key = 'key';
    public const string type = 'type';
    public const string source = 'source';
    public const string perNode = 'perNode';
    public const string digest = 'digest';
    public const string idleReason = 'idleReason';
    public const string rules = 'rules';
    public const string agentId = 'agentId';
    public const string name = 'name';
    public const string expression = 'expression';
    public const string lastRunAt = 'lastRunAt';
    public const string nextRunAt = 'nextRunAt';

    public function __construct(public readonly NodeDaemonPicture $picture)
    {
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->picture->nodeId,
            self::role => $this->picture->role->value,
            self::sampledAt => $this->picture->sampledAt,
            self::processes => $this->picture->processes === null
                ? null
                : DaemonMasterProcessRosterSignalData::rosterToArray($this->picture->processes),
            self::cron => $this->picture->cron === null ? null : self::cronToArray($this->picture->cron),
            self::environment => $this->picture->environment === null ? null : [
                self::catalogKeys => $this->picture->environment->catalogKeys,
                self::missingRequired => $this->picture->environment->missingRequired,
                self::fromExample => $this->picture->environment->fromExample,
                self::drifted => $this->picture->environment->drifted,
                self::orphans => $this->picture->environment->orphans,
                self::fingerprints => array_map(static fn (NodeEnvironmentFingerprint $fingerprint): array => [
                    self::key => $fingerprint->key,
                    self::type => $fingerprint->type,
                    self::source => $fingerprint->source->value,
                    self::perNode => $fingerprint->perNode,
                    self::digest => $fingerprint->digest,
                ], $this->picture->environment->fingerprints),
            ],
            self::standing => $this->picture->standing === null
                ? null
                : DaemonMasterStandingSignalData::standingToArray($this->picture->standing),
            self::http => $this->picture->http === null
                ? null
                : DaemonMasterHttpSignalData::httpToArray($this->picture->http),
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Parsed picture
     * @throws InvalidFormatException When a field is absent or invalid
     */
    public static function fromArray(array $data): static
    {
        $nodeId = self::requireString($data, self::nodeId);
        $role = NodeRole::tryFrom(self::requireString($data, self::role));
        if ($nodeId === '' || $role === null) {
            throw new InvalidFormatException('Daemon node picture carries an empty node id or invalid role');
        }

        $processes = self::requireNullableArray($data, self::processes);
        $cron = self::requireNullableArray($data, self::cron);
        $environment = self::requireNullableArray($data, self::environment);
        $standing = self::requireNullableArray($data, self::standing);
        $http = self::requireNullableArray($data, self::http);

        return new static(new NodeDaemonPicture(
            $nodeId,
            $role,
            self::requireInt($data, self::sampledAt),
            $processes === null ? null : DaemonMasterProcessRosterSignalData::rosterFromArray($processes),
            $cron === null ? null : self::cronFromArray($cron),
            $environment === null ? null : new NodeEnvironmentSummary(
                self::nonNegativeInt($environment, self::catalogKeys),
                self::nonNegativeInt($environment, self::missingRequired),
                self::nonNegativeInt($environment, self::fromExample),
                self::nonNegativeInt($environment, self::drifted),
                self::nonNegativeInt($environment, self::orphans),
                self::fingerprintsFromArray($environment),
            ),
            $standing === null ? null : DaemonMasterStandingSignalData::standingFromArray($standing),
            $http === null ? null : DaemonMasterHttpSignalData::httpFromArray($http),
        ));
    }

    /**
     * @param array<string, mixed> $data Environment summary
     * @param string $key Counter key
     * @return int Nonnegative count
     * @throws InvalidFormatException When the counter is absent, mistyped, or negative
     */
    private static function nonNegativeInt(array $data, string $key): int
    {
        $value = self::requireInt($data, $key);
        if ($value < 0) {
            throw new InvalidFormatException("Daemon environment count {$key} cannot be negative");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $environment Environment wire object
     * @return list<NodeEnvironmentFingerprint> Validated fingerprints
     * @throws InvalidFormatException When a fingerprint is absent, mistyped, or duplicated
     */
    private static function fingerprintsFromArray(array $environment): array
    {
        $rows = self::requireArray($environment, self::fingerprints);
        if (!array_is_list($rows)) {
            throw new InvalidFormatException('Daemon environment fingerprints must be a list');
        }
        $fingerprints = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidFormatException('Daemon environment fingerprint must be an object');
            }
            $key = self::requireString($row, self::key);
            $type = self::requireString($row, self::type);
            $source = EnvSource::tryFrom(self::requireString($row, self::source));
            $perNode = self::requireBool($row, self::perNode);
            $digest = self::requiredNullableString($row, self::digest);
            if ($key === '' || isset($seen[$key]) || !in_array($type, [
                EnvCatalogConstants::TYPE_STRING,
                EnvCatalogConstants::TYPE_INTEGER,
                EnvCatalogConstants::TYPE_FLOAT,
                EnvCatalogConstants::TYPE_BOOLEAN,
            ], true) || $source === null || ($digest === null) !== ($source === EnvSource::MISSING)
                || ($digest !== null && (strlen($digest) !== NodeEnvironmentFingerprint::DIGEST_HEX_LENGTH
                    || preg_match('/^[0-9a-f]+$/', $digest) !== 1))) {
                throw new InvalidFormatException('Daemon environment fingerprint has invalid key, type, source, or digest');
            }
            $seen[$key] = true;
            $fingerprints[] = new NodeEnvironmentFingerprint($key, $type, $source, $perNode, $digest);
        }
        return $fingerprints;
    }

    /**
     * @param DaemonCronPicture $cron Cron section of the node picture
     * @return array<string, mixed> Shared wire shape
     */
    public static function cronToArray(DaemonCronPicture $cron): array
    {
        return [
            self::idleReason => $cron->idleReason,
            self::rules => array_map(static fn (DaemonCronRulePicture $rule): array => [
                self::agentId => $rule->agentId,
                self::name => $rule->name,
                self::expression => $rule->expression,
                self::lastRunAt => $rule->lastRunAt,
                self::nextRunAt => $rule->nextRunAt,
            ], $cron->rules),
        ];
    }

    /**
     * @param array<string, mixed> $data Shared wire shape
     * @return DaemonCronPicture Parsed cron section
     * @throws InvalidFormatException When a required field is absent or invalid
     */
    public static function cronFromArray(array $data): DaemonCronPicture
    {
        $rows = self::requireArray($data, self::rules);
        if (!array_is_list($rows)) {
            throw new InvalidFormatException('Daemon cron picture rules must be a list');
        }
        $rules = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('Daemon cron picture rule must be an object');
            }
            $rules[] = new DaemonCronRulePicture(
                self::requiredNullableString($row, self::agentId),
                self::requireString($row, self::name),
                self::requireString($row, self::expression),
                self::requiredNullableInt($row, self::lastRunAt),
                self::requiredNullableInt($row, self::nextRunAt),
            );
        }
        return new DaemonCronPicture(self::requiredNullableString($data, self::idleReason), $rules);
    }

    /**
     * @param array<string, mixed> $data Wire object
     * @param string $key Required nullable string key
     * @return ?string String or explicit null
     * @throws InvalidFormatException When the key is absent or mistyped
     */
    private static function requiredNullableString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidFormatException('Payload carries no string or null under key ' . $key);
        }
        return self::optionalString($data, $key);
    }

    /**
     * @param array<string, mixed> $data Wire object
     * @param string $key Required nullable integer key
     * @return ?int Integer or explicit null
     * @throws InvalidFormatException When the key is absent or mistyped
     */
    private static function requiredNullableInt(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidFormatException('Payload carries no integer or null under key ' . $key);
        }
        return self::optionalInt($data, $key);
    }
}

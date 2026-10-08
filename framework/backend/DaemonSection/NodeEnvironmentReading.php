<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Environment\EnvResolution;
use Hilos\Environment\EnvSource;

/** Node-local environment values; only view() may turn them into a wire shape. */
final readonly class NodeEnvironmentReading
{
    public const string NODE_ID = 'nodeId';
    public const string CLUSTER = 'cluster';
    public const string READ_AT = 'readAt';
    public const string KEYS = 'keys';
    public const string ORPHANS = 'orphans';
    public const string KEY = 'key';
    public const string TYPE = 'type';
    public const string REQUIRED = 'required';
    public const string DECLARED_BY = 'declaredBy';
    public const string SENSITIVE = 'sensitive';
    public const string PER_NODE = 'perNode';
    public const string SOURCE = 'source';
    public const string VALUE = 'value';
    public const string DISK = 'disk';
    public const string KIND = 'kind';
    public const string TEXT = 'text';
    public const string LENGTH = 'length';
    public const string KIND_TEXT = 'text';
    public const string KIND_SECRET = 'secret';
    public const string KIND_NONE = 'none';
    public const string DECLARER_FRAMEWORK = 'framework';
    public const string DECLARER_PROJECT = 'project';

    /**
     * @param string $nodeId Node that read the values
     * @param int $readAt Reading timestamp
     * @param list<NodeEnvironmentKey> $keys Catalog keys in declaration order
     * @param list<NodeEnvironmentOrphan> $orphans Uncataloged .env entries in file order
     */
    public function __construct(
        public string $nodeId,
        public int $readAt,
        public array $keys,
        public array $orphans,
    ) {
    }

    /** @return NodeEnvironmentSummary Counts for the collector's value-free picture */
    public function summary(): NodeEnvironmentSummary
    {
        $missingRequired = 0;
        $fromExample = 0;
        $drifted = 0;
        foreach ($this->keys as $key) {
            if ($key->required && $key->process->source === EnvSource::MISSING) {
                $missingRequired++;
            }
            if ($key->process->source === EnvSource::EXAMPLE) {
                $fromExample++;
            }
            if ($key->disk !== null) {
                $drifted++;
            }
        }

        return new NodeEnvironmentSummary(count($this->keys), $missingRequired, $fromExample, $drifted, count($this->orphans));
    }

    /**
     * @param self $other Reading to compare
     * @return bool Whether its content is the same, regardless of read time
     */
    public function sameContent(self $other): bool
    {
        return $this->nodeId === $other->nodeId
            && $this->keys == $other->keys
            && $this->orphans == $other->orphans;
    }

    /**
     * @param bool $viewer Whether the recipient is in admin view mode
     * @param bool $cluster Whether the installation uses a cluster
     * @return array<string, mixed> Environment page data, with values masked for this recipient
     */
    public function view(bool $viewer, bool $cluster): array
    {
        $keys = [];
        foreach ($this->keys as $key) {
            $hidden = $viewer && !$key->adminViewVisible;
            $keys[] = [
                self::KEY => $key->key,
                self::TYPE => $key->type,
                self::REQUIRED => $key->required,
                self::DECLARED_BY => $key->declaredByFramework ? self::DECLARER_FRAMEWORK : self::DECLARER_PROJECT,
                self::SENSITIVE => $key->sensitive,
                self::PER_NODE => $key->perNode,
                self::SOURCE => $key->process->source->value,
                self::VALUE => self::valueView($key->process, $hidden, $key->sensitive),
                self::DISK => $hidden || $key->disk === null ? null : [
                    self::SOURCE => $key->disk->source->value,
                    self::VALUE => self::valueView($key->disk, false, $key->sensitive),
                ],
            ];
        }

        $orphans = [];
        foreach ($this->orphans as $orphan) {
            $orphans[] = [
                self::KEY => $orphan->key,
                self::VALUE => $viewer
                    ? HiddenValue::mark()
                    : [self::KIND => self::KIND_SECRET, self::LENGTH => mb_strlen($orphan->value)],
            ];
        }

        return [
            self::NODE_ID => $this->nodeId,
            self::CLUSTER => $cluster,
            self::READ_AT => $this->readAt,
            self::KEYS => $keys,
            self::ORPHANS => $orphans,
        ];
    }

    /**
     * @param EnvResolution $resolution Source and raw value
     * @param bool $hidden Whether a viewer must not see a present value
     * @param bool $sensitive Whether an administrator receives only its length
     * @return array<string, mixed> Masked value on the wire
     */
    private static function valueView(EnvResolution $resolution, bool $hidden, bool $sensitive): array
    {
        if ($resolution->source === EnvSource::MISSING) {
            return [self::KIND => self::KIND_NONE];
        }
        if ($hidden) {
            return HiddenValue::mark();
        }

        $text = match (true) {
            is_bool($resolution->value) => $resolution->value ? 'true' : 'false',
            default => (string)$resolution->value,
        };
        if ($sensitive) {
            return [self::KIND => self::KIND_SECRET, self::LENGTH => mb_strlen($text)];
        }

        return [self::KIND => self::KIND_TEXT, self::TEXT => $text];
    }
}

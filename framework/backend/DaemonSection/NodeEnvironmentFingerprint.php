<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvResolution;
use Hilos\Environment\EnvSource;
use Hilos\Environment\EnvValue;

/** A value-free sign of one catalog key in a node picture. */
final readonly class NodeEnvironmentFingerprint
{
    public const int DIGEST_HEX_LENGTH = 16;

    public function __construct(
        public string $key,
        public string $type,
        public EnvSource $source,
        public bool $perNode,
        public ?string $digest,
    ) {
    }

    /**
     * @param string $key Catalog key
     * @param string $type Catalog type
     * @param EnvResolution $process Value held by the node process
     * @param bool $perNode Whether differences between nodes are expected
     * @return self Fingerprint without the original value
     */
    public static function of(string $key, string $type, EnvResolution $process, bool $perNode): self
    {
        if ($process->source === EnvSource::MISSING) {
            return new self($key, $type, $process->source, $perNode, null);
        }

        $typed = match ($type) {
            EnvCatalogConstants::TYPE_BOOLEAN => EnvValue::booleanOf($process->value),
            EnvCatalogConstants::TYPE_INTEGER => EnvValue::integerOf($process->value),
            EnvCatalogConstants::TYPE_FLOAT => EnvValue::floatOf($process->value),
            EnvCatalogConstants::TYPE_STRING => (string)$process->value,
        };
        $canonical = $typed === null
            ? 'r:' . (string)$process->value
            : 'v:' . match ($type) {
                EnvCatalogConstants::TYPE_BOOLEAN => $typed ? 'true' : 'false',
                EnvCatalogConstants::TYPE_FLOAT => var_export($typed, true),
                default => (string)$typed,
            };

        return new self(
            $key,
            $type,
            $process->source,
            $perNode,
            substr(hash('sha256', $key . "\0" . $canonical), 0, self::DIGEST_HEX_LENGTH),
        );
    }

    /** @return self Same fingerprint with a collector label in place of the node digest */
    public function withDigest(?string $digest): self
    {
        return new self($this->key, $this->type, $this->source, $this->perNode, $digest);
    }
}

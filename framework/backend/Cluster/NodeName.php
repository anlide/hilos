<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/** Node names and the common human-readable node label. */
final class NodeName
{
    public const int MAX_LENGTH = 64;

    /**
     * @return string Trimmed name of this node
     * @throws EnvException When the required node name cannot be read
     * @throws ClusterConfigurationException When the name is too long or contains controls or line/paragraph separators
     */
    public static function fromEnv(): string
    {
        $name = trim(Hilos::$env[EnvConstants::CLUSTER_NODE_NAME]->string());
        if (mb_strlen($name) > self::MAX_LENGTH || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $name) === 1) {
            throw ClusterConfigurationException::invalidName();
        }

        return $name;
    }

    /**
     * @param string $nodeId Stable node id
     * @param ?string $name Readable node name, absent for a node whose name is unknown
     * @return string Name with id, or the id alone when the name is unknown
     */
    public static function label(string $nodeId, ?string $name): string
    {
        return $name === null ? $nodeId : "{$name} ({$nodeId})";
    }
}

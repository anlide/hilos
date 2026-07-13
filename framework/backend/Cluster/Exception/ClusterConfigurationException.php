<?php

declare(strict_types=1);

namespace Hilos\Cluster\Exception;

/**
 * Thrown when cluster mode is enabled but the node-identity configuration is
 * incomplete or invalid. There is no silent fallback: a misconfigured node
 * must fail loudly rather than join the cluster with an undefined identity.
 */
class ClusterConfigurationException extends ClusterException
{
    /**
     * Builds an exception for a required node-identity variable left empty.
     *
     * @param string $envName Environment variable name that must be set
     * @return self Configuration exception
     */
    public static function missingField(string $envName): self
    {
        return new self("Cluster is enabled but required node config '{$envName}' is empty");
    }

    /**
     * Builds an exception for an unrecognised node role value.
     *
     * @param string $role Rejected role value read from configuration
     * @return self Configuration exception
     */
    public static function invalidRole(string $role): self
    {
        return new self("Invalid cluster node role '{$role}'; expected 'master' or 'slave'");
    }
}

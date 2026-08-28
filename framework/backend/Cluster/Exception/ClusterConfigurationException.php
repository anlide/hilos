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

    /**
     * Builds an exception for a master whose id is absent from the master set.
     *
     * The expected-master-set defines the quorum, so a master that does not list
     * itself cannot count itself and would compute an off-by-one majority.
     *
     * @param string $nodeId Local node id missing from CLUSTER_MASTER_SET
     * @return self Configuration exception
     */
    public static function nodeNotInMasterSet(string $nodeId): self
    {
        return new self("Cluster node '{$nodeId}' is not listed in CLUSTER_MASTER_SET");
    }

    /**
     * Builds an exception for consensus timings that cannot elect a stable leader.
     *
     * @param string $detail What is wrong with the configured timings
     * @return self Configuration exception
     */
    public static function invalidTiming(string $detail): self
    {
        return new self("Invalid cluster consensus timing: {$detail}");
    }

    /**
     * Builds an exception for a cluster policy registered after the transport read it.
     *
     * The transport takes each policy once, while it builds, and holds it in a field
     * afterwards; a registration that arrives later would be accepted and then never
     * consulted. Refusing it names the real mistake — the moment, not the policy.
     *
     * @param string $policyKind Which policy door was registered too late ('placement' or 'connection')
     * @return self Configuration exception
     */
    public static function policyRegisteredTooLate(string $policyKind): self
    {
        return new self("Cluster {$policyKind} policy was registered after the transport already took it");
    }
}

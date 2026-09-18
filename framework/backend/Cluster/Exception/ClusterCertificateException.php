<?php

declare(strict_types=1);

namespace Hilos\Cluster\Exception;

use Hilos\Constants\CliCommands;
use Throwable;

/**
 * Thrown when a cluster certificate cannot be issued.
 *
 * Issuing happens on the operator's command line, not on a running node: every refusal here is
 * something the operator can fix and run the command again - a node name no certificate can
 * carry, an authority file that is not one, an authority past its end date - or OpenSSL failing
 * a step, which names its own reason.
 */
class ClusterCertificateException extends ClusterException
{
    /**
     * Builds an exception for a node name that is empty once trimmed.
     *
     * @return self Certificate exception
     */
    public static function emptyNodeName(): self
    {
        return new self('A node certificate needs the node id it is issued to; the one given is empty');
    }

    /**
     * Builds an exception for a node name longer than a certificate's common name may be.
     *
     * @param string $nodeId Node id that was given
     * @param int $maxLength Most characters a common name holds
     * @return self Certificate exception
     */
    public static function nodeNameTooLong(string $nodeId, int $maxLength): self
    {
        return new self("The node id '{$nodeId}' is longer than the {$maxLength} characters a certificate name holds");
    }

    /**
     * Builds an exception for an authority file that holds no certificate with its own key.
     *
     * @return self Certificate exception
     */
    public static function authorityUnreadable(): self
    {
        return new self(
            'The authority file does not hold a certificate followed by its private key, as '
            . CliCommands::CLUSTER_TLS_CA . ' prints it',
        );
    }

    /**
     * Builds an exception for an authority whose end date has come.
     *
     * @param string $date Date the authority expires on, Y-m-d
     * @return self Certificate exception
     */
    public static function authorityExpired(string $date): self
    {
        return new self(
            "The authority expires on {$date} and can sign nothing more; issue a new one with " . CliCommands::CLUSTER_TLS_CA,
        );
    }

    /**
     * Builds an exception for an OpenSSL step that failed.
     *
     * @param string $step What was being done, in a few words
     * @param ?string $reason OpenSSL's reason, null when it left none
     * @return self Certificate exception
     */
    public static function openSslFailed(string $step, ?string $reason): self
    {
        return new self($reason === null ? "OpenSSL could not {$step}" : "OpenSSL could not {$step}: {$reason}");
    }

    /**
     * Builds an exception for the temporary OpenSSL configuration that could not be written.
     *
     * @param Throwable $previous Failure of the file layer
     * @return self Certificate exception
     */
    public static function configurationUnwritable(Throwable $previous): self
    {
        return new self('The temporary OpenSSL configuration could not be written: ' . $previous->getMessage(), 0, $previous);
    }
}

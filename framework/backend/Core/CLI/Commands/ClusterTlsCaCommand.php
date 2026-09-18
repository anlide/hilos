<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Cluster\Exception\ClusterCertificateException;
use Hilos\Cluster\Tls\ClusterCertificateIssuer;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Random\RandomException;

/**
 * Cluster TLS authority command (HIL-1034).
 *
 * Issues the authority every node certificate of one cluster is signed by, and prints it - its
 * certificate followed by its private key - to stdout. The operator keeps it: the key never goes
 * to a node, and each node gets the certificate alone through cluster:tls:trust.
 *
 * Database-free by contract: nothing it does touches an installation, and it is run where there
 * is no installation yet.
 */
class ClusterTlsCaCommand implements CommandInterface, DatabaseFreeCommand
{
    /**
     * Why the three cluster:tls:* commands work in the CLI process - one reason, declared once.
     */
    public const string EXECUTION_REASON = 'issues key material for a node that may not run yet and prints it; nothing the'
        . ' installation owns is read or written, and where the file goes is the operator\'s to decide';

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (cluster:tls:ca)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TLS_CA;
    }

    /**
     * Declares the departure: this work happens in the CLI process, for the reason it names.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliRead(self::EXECUTION_REASON);
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Issue a cluster TLS authority, printed as certificate then key';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: cluster:tls:ca

Description:
  Issues a new certificate authority for the cluster's peer channel and prints
  its certificate followed by its private key. Keep the output to yourself: the
  key never goes to a node. Give the nodes its certificate alone
  (cluster:tls:trust) and sign each node's certificate with it
  (cluster:tls:issue). Nothing is written to disk.

Usage:
  php cli.php cluster:tls:ca > cluster-ca.pem

Examples:
  php cli.php cluster:tls:ca > cluster-ca.pem
  php cli.php cluster:tls:trust cluster-ca.pem > ca.pem
  php cli.php cluster:tls:issue m1 cluster-ca.pem > m1.pem
HELP;
    }

    /**
     * Issues the authority and prints it.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        try {
            echo ClusterCertificateIssuer::issueAuthority();
        } catch (ClusterCertificateException | RandomException $refusal) {
            fwrite(STDERR, $refusal->getMessage() . "\n");
            return ExitCode::ERROR;
        }

        return ExitCode::SUCCESS;
    }
}

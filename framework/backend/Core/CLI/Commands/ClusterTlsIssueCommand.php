<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Cluster\Exception\ClusterCertificateException;
use Hilos\Cluster\Tls\ClusterCertificateIssuer;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Random\RandomException;

/**
 * Cluster TLS node certificate command (HIL-1034).
 *
 * Issues the certificate of one node - its common name is the node id, the node's
 * CLUSTER_NODE_ID - signed by the authority cluster:tls:ca printed, and prints it followed by its
 * private key to stdout. That output is the node's CLUSTER_TLS_CERT_FILE and goes to that one
 * node only.
 *
 * Database-free by contract: nothing it does touches an installation, and it is run for a node
 * that may not exist yet.
 */
class ClusterTlsIssueCommand implements CommandInterface, DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (cluster:tls:issue)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TLS_ISSUE;
    }

    /**
     * Declares the departure: this work happens in the CLI process, for the reason it names.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliRead(ClusterTlsCaCommand::EXECUTION_REASON);
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Issue a node certificate signed by the cluster TLS authority, printed as certificate then key';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: cluster:tls:issue

Description:
  Issues the certificate of one cluster node, signed by the authority that
  cluster:tls:ca printed, and prints it followed by its private key. The node id
  becomes the certificate's name and must equal that node's CLUSTER_NODE_ID;
  the output is that node's CLUSTER_TLS_CERT_FILE. The certificate is valid for
  ten years, or for what is left of the authority if that is shorter. Nothing is
  written to disk.

Usage:
  php cli.php cluster:tls:issue <nodeId> <authorityFile> > <nodeId>.pem

Arguments:
  nodeId          The node's CLUSTER_NODE_ID, at most 64 characters
  authorityFile   What cluster:tls:ca printed: certificate followed by key

Examples:
  php cli.php cluster:tls:issue m1 cluster-ca.pem > m1.pem
HELP;
    }

    /**
     * Issues the node certificate and prints it.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: node id, then the authority file
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        $nodeId = $args[0] ?? null;
        $authorityFile = $args[1] ?? null;
        if ($nodeId === null || $authorityFile === null) {
            fwrite(STDERR, 'Usage: ' . CliCommands::CLUSTER_TLS_ISSUE . " <nodeId> <authorityFile>\n");
            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            echo ClusterCertificateIssuer::issueNode($nodeId, FsPath::read($authorityFile));
        } catch (ClusterCertificateException | FsException | RandomException $refusal) {
            fwrite(STDERR, $refusal->getMessage() . "\n");
            return ExitCode::ERROR;
        }

        return ExitCode::SUCCESS;
    }
}

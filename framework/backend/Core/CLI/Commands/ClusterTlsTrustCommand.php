<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Cluster\Exception\ClusterCertificateException;
use Hilos\Cluster\Tls\ClusterCertificateIssuer;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;

/**
 * Cluster TLS trust file command (HIL-1034).
 *
 * Prints the certificate of the authority cluster:tls:ca printed, without its key, to stdout.
 * That output is the CLUSTER_TLS_CA_FILE of every node. While the authority is being replaced,
 * the file carries the old and the new certificate one after the other.
 *
 * Database-free by contract: nothing it does touches an installation, and it is run where there
 * is no installation yet.
 */
class ClusterTlsTrustCommand implements CommandInterface, DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (cluster:tls:trust)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TLS_TRUST;
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
        return 'Print the cluster TLS authority certificate without its key, the trust file of every node';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: cluster:tls:trust

Description:
  Prints the certificate of the authority that cluster:tls:ca printed, without
  its private key. The output is the CLUSTER_TLS_CA_FILE of every node. To
  replace the authority, give the nodes a file with the old and the new
  certificate one after the other, restart them one at a time, reissue their
  certificates with the new authority, and drop the old certificate last.
  Nothing is written to disk.

Usage:
  php cli.php cluster:tls:trust <authorityFile> > ca.pem

Arguments:
  authorityFile   What cluster:tls:ca printed: certificate followed by key

Examples:
  php cli.php cluster:tls:trust cluster-ca.pem > ca.pem
HELP;
    }

    /**
     * Prints the authority certificate alone.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: the authority file
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        $authorityFile = $args[0] ?? null;
        if ($authorityFile === null) {
            fwrite(STDERR, 'Usage: ' . CliCommands::CLUSTER_TLS_TRUST . " <authorityFile>\n");
            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            echo ClusterCertificateIssuer::trustOf(FsPath::read($authorityFile));
        } catch (ClusterCertificateException | FsException $refusal) {
            fwrite(STDERR, $refusal->getMessage() . "\n");
            return ExitCode::ERROR;
        }

        return ExitCode::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Who is taking the schema rollout claim ({@see MigrationClaim}), and whether a row that
 * already carries that name may be taken back.
 *
 * Taking back is a fact only for a node's own start: its watchdog is the one process in the
 * container that rolls the schema out, and it does so before the daemon exists, so a row with
 * its name was left by a previous start that is dead now. No other process knows that about
 * itself - two operators on one host are alive at the same time - so everything else writes the
 * name with its process id and never takes its own row back. Two nodes configured with the same
 * CLUSTER_NODE_ID would take each other's live row; that is a configuration mistake this class
 * does not catch.
 */
final readonly class MigrationClaimHolder
{
    /** @var string Name written for a host that does not report one */
    public const string UNKNOWN_HOST = 'unknown-host';

    /** @var string Between the node name and the process id of a holder that is not a node start */
    private const string PROCESS_SEPARATOR = ':';

    /**
     * @param string $name Holder name written into the claim row
     * @param bool $mayTakeBack Whether a row carrying this name proves its writer is dead
     */
    public function __construct(
        public string $name,
        public bool $mayTakeBack,
    ) {
    }

    /**
     * The node's watchdog rolling the schema out on startup; the one holder that takes its row back.
     *
     * @return self Holder named after the node
     * @throws EnvException When CLUSTER_NODE_ID cannot be read
     */
    public static function nodeStart(): self
    {
        return new self(self::nodeName(), true);
    }

    /**
     * Any other process that changes the schema: a CLI command, a restore, a test database reset.
     *
     * @return self Holder named after the node and this process id
     * @throws EnvException When CLUSTER_NODE_ID cannot be read
     */
    public static function process(): self
    {
        return new self(self::nodeName() . self::PROCESS_SEPARATOR . getmypid(), false);
    }

    /**
     * `gethostname()` is a `uname(2)` read of the local host name, no resolver behind it; under
     * docker it is the container's own name, which is what tells two unclustered nodes apart.
     *
     * @return string CLUSTER_NODE_ID, the host name when it is empty, or a stand-in for neither
     * @throws EnvException When CLUSTER_NODE_ID cannot be read
     */
    private static function nodeName(): string
    {
        $node = trim(Hilos::$env[EnvConstants::CLUSTER_NODE_ID]->string());
        if ($node !== '') {
            return $node;
        }

        $host = gethostname();

        return $host === false ? self::UNKNOWN_HOST : $host;
    }
}

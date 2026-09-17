<?php

declare(strict_types=1);

/**
 * Where every log line of a node lands, as a table the live-stand check asserts (HIL-1018).
 *
 * `scripts/check-log-streams.php` provokes the sources and `scripts/log-stream-verdicts.php`
 * judges the snapshots; this file only says WHAT is expected. One record per row of the
 * HIL-872 map (`hilos-ops/maps/HIL-872-log-streams.md`, the measurement of 2026-09-12), and
 * a few more for what a landed fix promised since. The map stays history and is not edited:
 * where a fix has overtaken it the record carries today's contract and names the row it
 * supersedes. Row 3 has no record on purpose: its line cannot be reached today (P-353), and a
 * record pinning that would pin a defect as the norm.
 *
 * A record is:
 *   rows        the map rows the record proves, by number.
 *   scenario    which of the five provocations produces the line — the ids are declared in the
 *               verdicts file, and the command walks them in that order.
 *   source      one human phrase for the red line.
 *   pattern     a PCRE matched per line, or null for a record that asserts emptiness only. An
 *               anchor `^` means the start of a LINE: that is how PHP's own fatal text, printed
 *               raw, is told from the same words quoted inside a watchdog line.
 *   lands       stream tokens that must hold a matching line — any one of the streams a token
 *               resolves to. Polled until it does.
 *   never       stream tokens that must not — every stream the token resolves to. Judged once,
 *               on the closing snapshot, after every `lands` of the scenario has arrived. Never
 *               empty on a record with a pattern: it is the only half that can catch a stream
 *               MOVING, because a line copied to one more place still satisfies `lands`.
 *   nowhere     true when `lands` is empty on purpose: the line must not exist at all.
 *   empty       concrete files that must exist and hold zero bytes.
 *   supersedes  optional: what the 2026-09-12 map says where a landed fix has overtaken it.
 *
 * The tokens, literally: `container-log` (stdout of PID 1), `container-log-stderr` (its stderr,
 * read apart), `daemon.log`, `daemon-error.log`, `daemon-raw.log`, `daemon-error-raw.log`,
 * `worker-regular-*.log`, `worker-regular-*.error.log`, `worker-monopolistic-*.log`,
 * `worker-monopolistic-*.error.log`, `agent-*.log`, `agent-*.error.log`, and `any-file` (every
 * `*.log` of the log directory; legal only inside `never`). A `*` is one name segment and does
 * not cross a dot.
 *
 * Every text below is quoted from the line of code that writes it, named beside it, so that a
 * red here points at a stream that moved and not at a phrase somebody paraphrased.
 *
 * This file only returns a list and executes nothing; `framework/tests/Unit/LogStreamVerdictsTest.php`
 * holds it to the table's own rules.
 */

return [
    // ---------------------------------------------------------------- alive
    [
        'rows' => [1],
        'scenario' => 'alive',
        'source' => 'watchdog INFO goes to the container log and to no file',
        // framework/backend/Core/Daemon/DockerManager.php: "Docker watchdog started",
        // "Starting daemon process...", "Daemon started (startup time: ".
        'pattern' => '/Docker watchdog started|Starting daemon process\.\.\.|Daemon started \(startup time: /',
        'lands' => ['container-log'],
        'never' => ['daemon.log', 'daemon-error.log', 'any-file'],
        'nowhere' => false,
        'empty' => [],
    ],
    [
        'rows' => [5],
        'scenario' => 'alive',
        'source' => 'master INFO goes to daemon.log only',
        // framework/backend/Core/Daemon/DaemonManager.php: "Daemon started with epoll";
        // framework/backend/Socket/Server/WorkerServer.php: "Worker #{$workerIndex} started [type={$type}]".
        'pattern' => '/Daemon started with epoll|Worker #\d+ started \[type=(regular|monopolistic)\]/',
        'lands' => ['daemon.log'],
        'never' => ['container-log', 'container-log-stderr', 'daemon-error.log'],
        'nowhere' => false,
        'empty' => [],
    ],
    [
        'rows' => [8],
        'scenario' => 'alive',
        'source' => 'a regular worker\'s own line is filed by the master into the worker\'s stream',
        // framework/backend/Core/Daemon/WorkerManager.php: "Connected to daemon".
        'pattern' => '/Connected to daemon/',
        'lands' => ['worker-regular-*.log'],
        'never' => ['daemon.log', 'container-log'],
        'nowhere' => false,
        'empty' => [],
    ],
    [
        'rows' => [10],
        'scenario' => 'alive',
        'source' => 'a monopolistic worker\'s line is filed into its own stream, indexed after the regular ones',
        // The same line; that the indexes continue rather than restart is asserted by the
        // command over the snapshot's names (logStreamSharedWorkerIndexes()).
        'pattern' => '/Connected to daemon/',
        'lands' => ['worker-monopolistic-*.log'],
        'never' => ['container-log'],
        'nowhere' => false,
        'empty' => [],
    ],
    [
        'rows' => [11],
        'scenario' => 'alive',
        'source' => 'an agent\'s start line lands in the agent\'s own stream with its level after the stamp',
        // framework/backend/Utils/Logger.php: "Agent started '{$agentType}'", filed by
        // framework/backend/Log/AgentLogStream.php as "[stamp] [INFO] text".
        'pattern' => '/^\[[^\]]+\] \[INFO\] Agent started \'hilos_logs\'$/',
        'lands' => ['agent-*.log'],
        'never' => ['worker-regular-*.log', 'worker-monopolistic-*.log', 'daemon.log', 'container-log'],
        'nowhere' => false,
        'empty' => [],
        'supersedes' => 'map row 11 says the level is not written into the agent file; since HIL-1017 it is,'
            . ' between the stamp and the text',
    ],
    [
        'rows' => [11],
        'scenario' => 'alive',
        'source' => 'a line asked for through the live daemon travels the real path into the agent\'s stream',
        // framework/backend/Log/LogStoreAgent.php: "{$message} #{$i}" for cli.php test:log:append.
        'pattern' => '/^\[[^\]]+\] \[INFO\] hilos log stream check #1$/',
        'lands' => ['agent-*.log'],
        'never' => ['worker-regular-*.log', 'worker-monopolistic-*.log', 'daemon.log', 'container-log'],
        'nowhere' => false,
        'empty' => [],
    ],
    [
        'rows' => [14, 15],
        'scenario' => 'alive',
        'source' => 'the raw pair exists beside the Logger files and stays empty while nothing goes past the Logger',
        'pattern' => null,
        'lands' => [],
        'never' => [],
        'nowhere' => false,
        'empty' => ['daemon-raw.log', 'daemon-error-raw.log'],
    ],
    // ---------------------------------------------------------------- crash
    [
        'rows' => [2, 17],
        'scenario' => 'crash',
        'source' => 'the watchdog\'s ERROR carries the crash reason on the FIRST failure, in the container log',
        // framework/backend/Core/Daemon/DockerManager.php + DaemonCrashReason.php: "Daemon process has
        // stopped unexpectedly: killed by signal 9, survived 12.34s. Last daemon output: ...".
        'pattern' => '/ERROR: Daemon process has stopped unexpectedly: killed by signal 9, survived \d+\.\d\ds\. Last daemon output: /',
        'lands' => ['container-log'],
        'never' => ['daemon.log', 'container-log-stderr'],
        'nowhere' => false,
        'empty' => [],
    ],
    [
        'rows' => [2, 17],
        'scenario' => 'crash',
        'source' => 'the same crash line, without its ERROR prefix, in daemon-error.log',
        'pattern' => '/^\[[^\]]+\] Daemon process has stopped unexpectedly: killed by signal 9, survived \d+\.\d\ds\. Last daemon output: /',
        'lands' => ['daemon-error.log'],
        'never' => ['daemon.log', 'container-log-stderr'],
        'nowhere' => false,
        'empty' => [],
    ],
    [
        'rows' => [17],
        'scenario' => 'crash',
        'source' => 'one kill is no run of failures: the threshold line does not appear',
        // framework/backend/Core/Daemon/DockerManager.php: "Daemon failed to start N times in a row".
        'pattern' => '/Daemon failed to start \d+ times in a row/',
        'lands' => [],
        'never' => ['container-log', 'daemon-error.log', 'any-file'],
        'nowhere' => true,
        'empty' => [],
    ],
    [
        'rows' => [23],
        'scenario' => 'crash',
        'source' => 'a worker\'s farewell on a closed daemon connection lands nowhere (today\'s behavior, P-317)',
        // framework/backend/Core/Daemon/WorkerManager.php: "daemon connection closed, worker exits".
        'pattern' => '/daemon connection closed, worker exits/',
        'lands' => [],
        'never' => ['any-file', 'container-log', 'container-log-stderr'],
        'nowhere' => true,
        'empty' => [],
    ],
    [
        'rows' => [23],
        'scenario' => 'crash',
        'source' => 'a worker\'s farewell on a vanished parent lands nowhere (today\'s behavior, P-317)',
        // framework/backend/Core/Daemon/WorkerManager.php: "daemon parent gone, worker exits".
        'pattern' => '/daemon parent gone, worker exits/',
        'lands' => [],
        'never' => ['any-file', 'container-log', 'container-log-stderr'],
        'nowhere' => true,
        'empty' => [],
    ],
    // ---------------------------------------------------------------- master-error
    [
        'rows' => [19, 6],
        'scenario' => 'master-error',
        'source' => 'the master\'s PHP error handler files a warning into both Logger files and nowhere raw',
        // framework/backend/Core/Daemon/BaseManager.php errorHandler(): "WARNING in <basename>:<line> - <message>",
        // written by Logger::error as "[stamp] ERROR: ..." in daemon.log and "[stamp] ..." in daemon-error.log.
        // Anchored: the watchdog quotes daemon-error.log's tail into the container log mid-line.
        'pattern' => '/^\[[^\]]+\] (ERROR: )?WARNING in hilos-log-stream-probe\.php:\d+ - fopen\(\/nonexistent\/hilos-log-stream-probe-warning\)/',
        'lands' => ['daemon.log', 'daemon-error.log'],
        'never' => ['container-log', 'container-log-stderr', 'daemon-raw.log'],
        'nowhere' => false,
        'empty' => [],
    ],
    [
        'rows' => [14],
        'scenario' => 'master-error',
        'source' => 'what PHP prints past the Logger on a fatal lands in the raw stdout twin',
        // PHP itself, display_errors=1 in this image: "Fatal error: Allowed memory size of N bytes exhausted".
        'pattern' => '/^Fatal error: Allowed memory size of \d+ bytes exhausted/',
        'lands' => ['daemon-raw.log'],
        'never' => ['daemon-error-raw.log', 'container-log', 'container-log-stderr', 'daemon.log', 'daemon-error.log'],
        'nowhere' => false,
        'empty' => [],
    ],
    [
        'rows' => [15],
        'scenario' => 'master-error',
        'source' => 'the raw stderr twin stays empty through the fatal (log_errors=0 in this image)',
        'pattern' => null,
        'lands' => [],
        'never' => [],
        'nowhere' => false,
        'empty' => ['daemon-error-raw.log'],
    ],
    [
        'rows' => [17],
        'scenario' => 'master-error',
        'source' => 'the watchdog\'s crash line names the raw stream the fatal really landed in (HIL-1015)',
        // framework/backend/Core/Daemon/DaemonOutputQuote.php renders "<names>: <text>" per stream.
        'pattern' => '/daemon-raw\.log: Fatal error: Allowed memory size of/',
        'lands' => ['container-log', 'daemon-error.log'],
        'never' => ['daemon.log', 'container-log-stderr'],
        'nowhere' => false,
        'empty' => [],
    ],
    [
        'rows' => [17],
        'scenario' => 'master-error',
        'source' => 'a death that printed something is never reported as one that printed nothing (HIL-1015)',
        // framework/backend/Core/Daemon/DaemonOutputQuote.php: PRINTED_NOTHING.
        'pattern' => '/\(the daemon printed nothing\)/',
        'lands' => [],
        'never' => ['container-log', 'daemon-error.log'],
        'nowhere' => true,
        'empty' => [],
    ],
    // ---------------------------------------------------------------- agent-in-master
    [
        'rows' => [13],
        'scenario' => 'agent-in-master',
        'source' => 'the master\'s freeze watchdog files its stuck line into the agent\'s own error stream (HIL-1017)',
        // framework/backend/ProtectedMode/ProtectedModeWatchdog.php stuckLogLine():
        // "Freeze for '<operation>' on phase '<phase>' is stuck: <problem>".
        'pattern' => '/Freeze for \'log-stream-check\' on phase \'[^\']+\' is stuck: /',
        'lands' => ['agent-*.error.log'],
        'never' => ['daemon-raw.log', 'container-log', 'container-log-stderr', 'agent-*.log', 'daemon.log'],
        'nowhere' => false,
        'empty' => [],
    ],
    [
        'rows' => [13],
        'scenario' => 'agent-in-master',
        'source' => 'the notifier\'s line about nobody to write to is filed the same way (HIL-1017)',
        // framework/backend/ProtectedMode/ProtectedModeAlertNotifier.php mailEveryone():
        // "A <what> is up and there is nobody to write to: HILOS_PROTECTED_MODE_ALERT_EMAILS names no address".
        'pattern' => '/is up and there is nobody to write to: HILOS_PROTECTED_MODE_ALERT_EMAILS names no address/',
        'lands' => ['agent-*.error.log'],
        'never' => ['daemon-raw.log', 'container-log', 'container-log-stderr', 'agent-*.log', 'daemon.log'],
        'nowhere' => false,
        'empty' => [],
    ],
    [
        'rows' => [13],
        'scenario' => 'agent-in-master',
        'source' => 'the agent-line marker appears in no file and in no container stream (HIL-1017)',
        // framework/backend/Utils/Logger.php: AGENT_LOG_MARKER. That string anywhere is HIL-1017 undone.
        'pattern' => '/\[AGENT_LOG\]/',
        'lands' => [],
        'never' => ['any-file', 'container-log', 'container-log-stderr'],
        'nowhere' => true,
        'empty' => [],
    ],
    // ---------------------------------------------------------------- rotation-refused
    [
        'rows' => [18],
        'scenario' => 'rotation-refused',
        'source' => 'a PHP warning inside the watchdog lands in the container log and daemon-error.log, and nowhere else',
        // framework/backend/Core/Daemon/BaseManager.php errorHandler() in the WATCHDOG, reached through
        // framework/backend/Log/LogRotator.php rotate(): rename() of a mount point warns "Device or resource
        // busy" before it returns false. The lever was chosen for map row 3 (HIL-1016's "Log rotation could
        // not move ..."), and the first live run showed that line is unreachable today: the warning reaches
        // the watchdog's handler first, and the watchdog leaves (P-353). What the lever does prove is row 18.
        'pattern' => '/WARNING in LogRotator\.php:\d+ - rename\(\/var\/log\/hilos\/pinned-by-log-stream-check\.log,/',
        'lands' => ['container-log', 'daemon-error.log'],
        'never' => ['daemon.log', 'container-log-stderr', 'daemon-raw.log', 'daemon-error-raw.log'],
        'nowhere' => false,
        'empty' => [],
    ],
];

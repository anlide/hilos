<?php

declare(strict_types=1);

/**
 * Judging where a log line landed, without a stand in the room (HIL-1018).
 *
 * The check that proves the HIL-872 map on a live stand has three parts, split the way the
 * stand teardown is: `scripts/log-streams.php` is the DATA — one record per map row, saying
 * which streams must hold a line and which must not; `scripts/check-log-streams.php` is the
 * COMMAND — docker, containers, provocations, polling; and this file is the pure half between
 * them. It reads a snapshot — the streams of a node as texts, keyed by name — and says which
 * records the snapshot disappoints. Nothing here opens a file, starts a process or knows what
 * docker is, so `framework/tests/Unit/LogStreamVerdictsTest.php` can require it by path and
 * judge it against snapshots written by hand.
 *
 * A snapshot is `array<string, string>`: the two container streams under their token names,
 * and every live `*.log` file of the log directory under its basename, each mapped to the text
 * it holds. A stream that does not exist has no key at all; an existing empty file has an empty
 * string. The command takes a snapshot from the moment a scenario armed itself, so the texts are
 * what the scenario caused and not what the stand did before it.
 *
 * Two halves of every record answer two different questions, and they are judged at different
 * moments. `lands` asks whether a line ARRIVED — the command polls it until it does or the
 * deadline passes. `never` asks whether a line is ANYWHERE it must not be, and a line still in
 * flight would read as absent, so it is judged once, on the closing snapshot, after every `lands`
 * of the scenario has arrived. `judgeLogStreamLandings()` is the polled half; `judgeLogStreamRecord()`
 * is the whole verdict.
 *
 * This file only declares constants and functions and executes nothing.
 */

/** Stand up, settle, and ask the live daemon for one line. */
const LOG_STREAM_SCENARIO_ALIVE = 'alive';

/** One SIGKILL of the master. */
const LOG_STREAM_SCENARIO_CRASH = 'crash';

/** The probe armed inside the master: a real warning, then a real fatal. */
const LOG_STREAM_SCENARIO_MASTER_ERROR = 'master-error';

/** A freeze left idle until the master's own watchdog writes an agent line. */
const LOG_STREAM_SCENARIO_AGENT_IN_MASTER = 'agent-in-master';

/** A file rotation cannot move, mounted into the log directory before the watchdog starts. */
const LOG_STREAM_SCENARIO_ROTATION_REFUSED = 'rotation-refused';

/**
 * The scenario ids a record may name, in the order the command walks them.
 *
 * @var array<int, string>
 */
const LOG_STREAM_SCENARIOS = [
    LOG_STREAM_SCENARIO_ALIVE,
    LOG_STREAM_SCENARIO_CRASH,
    LOG_STREAM_SCENARIO_MASTER_ERROR,
    LOG_STREAM_SCENARIO_AGENT_IN_MASTER,
    LOG_STREAM_SCENARIO_ROTATION_REFUSED,
];

/** The stdout of the container's PID 1 — what `docker logs` prints to its own stdout. */
const LOG_STREAM_CONTAINER_LOG = 'container-log';

/** The stderr of PID 1, read apart from stdout: the map tells the two streams apart, so does this. */
const LOG_STREAM_CONTAINER_LOG_STDERR = 'container-log-stderr';

/** Every `*.log` file under the log directory. Legal only inside `never`: nothing lands "in every file". */
const LOG_STREAM_ANY_FILE = 'any-file';

/**
 * The file tokens, literally and exhaustively. A `*` stands for one name segment — a worker
 * index, a sanitized agent id — and never crosses a dot, so `agent-*.log` does not match an
 * agent's `.error.log` twin.
 *
 * The raw pair and the worker and agent shapes are derived in the framework, not here:
 * `DaemonRawStream::SUFFIX` and `LogStreamConstants` own them. A host-side script cannot load
 * the framework, so the names are spelled once in this list and the unit test holds the list to
 * the framework's derivation.
 *
 * @var array<int, string>
 */
const LOG_STREAM_FILE_TOKENS = [
    'daemon.log',
    'daemon-error.log',
    'daemon-raw.log',
    'daemon-error-raw.log',
    'worker-regular-*.log',
    'worker-regular-*.error.log',
    'worker-monopolistic-*.log',
    'worker-monopolistic-*.error.log',
    'agent-*.log',
    'agent-*.error.log',
];

/**
 * Every token `lands`, `never` and `empty` may be written in.
 *
 * @var array<int, string>
 */
const LOG_STREAM_TOKENS = [
    LOG_STREAM_CONTAINER_LOG,
    LOG_STREAM_CONTAINER_LOG_STDERR,
    LOG_STREAM_ANY_FILE,
    ...LOG_STREAM_FILE_TOKENS,
];

/**
 * The keys every record carries. `supersedes` is the one optional key and is not here.
 *
 * @var array<int, string>
 */
const LOG_STREAM_RECORD_KEYS = ['rows', 'scenario', 'source', 'pattern', 'lands', 'never', 'nowhere', 'empty'];

/** The one key a record may leave out: what the 2026-09-12 map says where a landed fix has overtaken it. */
const LOG_STREAM_RECORD_OPTIONAL_KEY = 'supersedes';

/** How many rows the HIL-872 map has; a record naming a row outside 1..this names nothing. */
const LOG_STREAM_MAP_ROWS = 23;

/** The wildcard of a file token, standing for one name segment. */
const LOG_STREAM_TOKEN_WILDCARD = '*';

/** What every live stream file ends with; the framework's `LogStreamConstants::STREAM_SUFFIX` owns it. */
const LOG_STREAM_FILE_EXTENSION = '.log';

/** How much of a stream a red verdict quotes: enough to see the line that arrived instead. */
const LOG_STREAM_TAIL_LINES = 12;

/** The two container streams, which are named by their token and never by a glob. */
const LOG_STREAM_CONTAINER_TOKENS = [LOG_STREAM_CONTAINER_LOG, LOG_STREAM_CONTAINER_LOG_STDERR];

/**
 * The prefix of a worker stream's name, and what follows it: `worker-<type>-<index>.log`.
 * Spelled here for the index check below; the framework's `LogStreamConstants` owns the shape.
 */
const LOG_STREAM_WORKER_INDEX_PATTERN = '/^worker-(regular|monopolistic)-(\d+)\.log$/';

/**
 * The streams of a snapshot a token names, in the snapshot's order.
 *
 * A container token names itself when the snapshot holds it. A file token is matched against the
 * file names, with the wildcard standing for one segment. `any-file` names every file. An empty
 * answer is a fact and not an error: a `lands` token that resolves to nothing has not landed, and a
 * `never` token that resolves to nothing has nothing to forbid.
 *
 * @param string $token One of {@see LOG_STREAM_TOKENS}.
 * @param array<string, string> $snapshot The streams, keyed by name.
 * @return array<int, string> The names the token resolves to.
 */
function resolveLogStreamToken(string $token, array $snapshot): array
{
    if (in_array($token, LOG_STREAM_CONTAINER_TOKENS, true)) {
        return array_key_exists($token, $snapshot) ? [$token] : [];
    }

    $names = [];
    foreach (array_keys($snapshot) as $name) {
        if (in_array($name, LOG_STREAM_CONTAINER_TOKENS, true)) {
            continue;
        }
        if ($token === LOG_STREAM_ANY_FILE || preg_match(logStreamTokenPattern($token), $name) === 1) {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * The regular expression a file token turns into: the token quoted, its wildcard standing for one
 * segment that cannot cross a dot.
 *
 * @param string $token A file token.
 */
function logStreamTokenPattern(string $token): string
{
    return '/^' . str_replace(preg_quote(LOG_STREAM_TOKEN_WILDCARD, '/'), '[^.]+', preg_quote($token, '/')) . '$/';
}

/**
 * Whether a token names one concrete stream rather than a family of them.
 *
 * @param string $token One of {@see LOG_STREAM_TOKENS}.
 */
function logStreamTokenIsConcrete(string $token): bool
{
    return $token !== LOG_STREAM_ANY_FILE && !str_contains($token, LOG_STREAM_TOKEN_WILDCARD);
}

/**
 * The whole verdict on one record: what has not landed, what is where it must not be, and what
 * is not empty. An empty list is a record the snapshot satisfies.
 *
 * Meant for the closing snapshot of a scenario, after every `lands` has arrived — see the file
 * header for why `never` may not be judged earlier.
 *
 * @param array{rows: array<int, int>, scenario: string, source: string, pattern: string|null,
 *     lands: array<int, string>, never: array<int, string>, nowhere: bool,
 *     empty: array<int, string>} $record One record of the table.
 * @param array<string, string> $snapshot The streams, keyed by name.
 * @return array<int, array{record: array<string, mixed>, stream: string, reason: string, line: int|null}>
 */
function judgeLogStreamRecord(array $record, array $snapshot): array
{
    return [
        ...judgeLogStreamLandings($record, $snapshot),
        ...judgeLogStreamAbsences($record, $snapshot),
        ...judgeLogStreamEmptiness($record, $snapshot),
    ];
}

/**
 * The polled half: every `lands` token of the record that no stream satisfies yet.
 *
 * A token is satisfied by ANY of the streams it resolves to holding a line the pattern matches —
 * `agent-*.log` asks that some agent wrote the line, not that every agent did. A `nowhere`
 * record and a record without a pattern have nothing to land and always answer with nothing.
 *
 * @param array{pattern: string|null, lands: array<int, string>, nowhere: bool} $record One record of the table.
 * @param array<string, string> $snapshot The streams, keyed by name.
 * @return array<int, array{record: array<string, mixed>, stream: string, reason: string, line: int|null}>
 */
function judgeLogStreamLandings(array $record, array $snapshot): array
{
    if ($record['pattern'] === null || $record['nowhere']) {
        return [];
    }

    $verdicts = [];
    foreach ($record['lands'] as $token) {
        $names = resolveLogStreamToken($token, $snapshot);
        if ($names === []) {
            $verdicts[] = logStreamVerdict($record, $token, 'expected in ' . $token . ', no such stream', null);
            continue;
        }
        foreach ($names as $name) {
            if (logStreamMatchingLine($record['pattern'], $snapshot[$name]) !== null) {
                continue 2;
            }
        }
        $verdicts[] = logStreamVerdict(
            $record,
            $token,
            'expected in ' . $token . ', not there (looked in ' . implode(', ', $names) . ')',
            null,
        );
    }

    return $verdicts;
}

/**
 * Every `never` token of the record that a stream disappoints: the first matching line of each
 * such stream, by number.
 *
 * Unlike a landing, a forbidden line is judged in EVERY stream the token resolves to — the point
 * of `never` is to catch a stream that started being copied to one more place.
 *
 * @param array{pattern: string|null, never: array<int, string>} $record One record of the table.
 * @param array<string, string> $snapshot The streams, keyed by name.
 * @return array<int, array{record: array<string, mixed>, stream: string, reason: string, line: int|null}>
 */
function judgeLogStreamAbsences(array $record, array $snapshot): array
{
    if ($record['pattern'] === null) {
        return [];
    }

    $verdicts = [];
    foreach ($record['never'] as $token) {
        foreach (resolveLogStreamToken($token, $snapshot) as $name) {
            $line = logStreamMatchingLine($record['pattern'], $snapshot[$name]);
            if ($line !== null) {
                $verdicts[] = logStreamVerdict($record, $name, 'forbidden in ' . $name . ', found at line ' . $line, $line);
            }
        }
    }

    return $verdicts;
}

/**
 * Every `empty` token of the record that is either missing or holds bytes.
 *
 * Both halves are asserted on purpose: "the raw pair exists beside the Logger files and stays
 * empty" is two facts, and a missing file would satisfy a check that only asked for zero bytes.
 *
 * @param array{empty: array<int, string>} $record One record of the table.
 * @param array<string, string> $snapshot The streams, keyed by name.
 * @return array<int, array{record: array<string, mixed>, stream: string, reason: string, line: int|null}>
 */
function judgeLogStreamEmptiness(array $record, array $snapshot): array
{
    $verdicts = [];
    foreach ($record['empty'] as $token) {
        $names = resolveLogStreamToken($token, $snapshot);
        if ($names === []) {
            $verdicts[] = logStreamVerdict($record, $token, 'expected present and empty: ' . $token . ' is missing', null);
            continue;
        }
        foreach ($names as $name) {
            $bytes = strlen($snapshot[$name]);
            if ($bytes > 0) {
                $verdicts[] = logStreamVerdict($record, $name, 'expected empty: ' . $name . ' holds ' . $bytes . ' byte(s)', null);
            }
        }
    }

    return $verdicts;
}

/**
 * One disappointed expectation, in the shape every judge returns.
 *
 * @param array<string, mixed> $record The record that was disappointed.
 * @param string $stream The token or stream name that disappointed it.
 * @param string $reason How, in the words the red text prints.
 * @param int|null $line The line the forbidden text was found at, when there is one.
 * @return array{record: array<string, mixed>, stream: string, reason: string, line: int|null}
 */
function logStreamVerdict(array $record, string $stream, string $reason, ?int $line): array
{
    return ['record' => $record, 'stream' => $stream, 'reason' => $reason, 'line' => $line];
}

/**
 * The number of the first line of a text the pattern matches, or null when none does.
 *
 * Matched line by line rather than over the whole text: a pattern anchored with `^` then means
 * "at the start of a line", which is how the table tells PHP's own fatal text — printed raw at
 * the start of a line — from the same words quoted inside a watchdog line.
 *
 * @param string $pattern A PCRE the table validated.
 * @param string $text The stream.
 */
function logStreamMatchingLine(string $pattern, string $text): ?int
{
    foreach (logStreamLines($text) as $index => $line) {
        if (preg_match($pattern, $line) === 1) {
            return $index + 1;
        }
    }

    return null;
}

/**
 * The lines of a stream, without a trailing empty one.
 *
 * @param string $text The stream.
 * @return array<int, string>
 */
function logStreamLines(string $text): array
{
    if ($text === '') {
        return [];
    }
    $lines = preg_split('/\R/', rtrim($text, "\r\n"));

    return $lines === false ? [] : $lines;
}

/**
 * The red text for one disappointed expectation: the map rows, the scenario, the literal pattern,
 * which stream disappointed it and how, and the tail of every stream the record names.
 *
 * @param array{record: array{rows: array<int, int>, scenario: string, source: string, pattern: string|null,
 *     lands: array<int, string>, never: array<int, string>, empty: array<int, string>},
 *     stream: string, reason: string, line: int|null} $verdict What a judge returned.
 * @param array<string, string> $snapshot The streams the verdict was made on.
 * @return string Whole lines, ready to print.
 */
function renderLogStreamFailure(array $verdict, array $snapshot): string
{
    $record = $verdict['record'];
    $text = sprintf(
        "RED map row %s, scenario %s: %s\n  pattern: %s\n  %s\n",
        implode('+', $record['rows']),
        $record['scenario'],
        $record['source'],
        $record['pattern'] ?? '(none - an emptiness record)',
        $verdict['reason'],
    );

    $named = [];
    foreach ([...$record['lands'], ...$record['never'], ...$record['empty']] as $token) {
        foreach (resolveLogStreamToken($token, $snapshot) as $name) {
            $named[$name] = true;
        }
    }
    foreach (array_keys($named) as $name) {
        $text .= '  --- tail of ' . $name . ' (' . strlen($snapshot[$name]) . " bytes)\n";
        foreach (array_slice(logStreamLines($snapshot[$name]), -LOG_STREAM_TAIL_LINES) as $line) {
            $text .= '  | ' . $line . "\n";
        }
    }

    return $text;
}

/**
 * The table's own rules, and every way the given records break them.
 *
 * Shared by the command, which refuses to run over a broken table, and by the unit test, which
 * pins that the table in the repository is not broken. An empty list is a table in order.
 *
 * The rules, and why each is one: a record with an empty `never` is a defect of the table —
 * `never` is the only half that can catch a stream MOVING, because a line copied to one more
 * place still satisfies `lands`. An empty `lands` is legal only under `nowhere`, otherwise an
 * empty list reads as a typo and as a claim at once. A record without a pattern asserts
 * emptiness and nothing else, so it has to say which streams. Every `source` is unique because
 * the red text names a record by it. Every pattern compiles here rather than at the moment a
 * stand is waiting on it.
 *
 * @param array<int, mixed> $records What `scripts/log-streams.php` returned.
 * @return array<int, string> One line per problem.
 */
function validateLogStreamRecords(array $records): array
{
    $problems = [];
    $sources = [];
    foreach ($records as $index => $record) {
        $where = 'record #' . $index;
        if (!is_array($record)) {
            $problems[] = $where . ': not an array';
            continue;
        }
        $keyProblems = logStreamRecordKeyProblems($record, $where);
        if ($keyProblems !== []) {
            $problems = [...$problems, ...$keyProblems];
            continue;
        }
        $where .= ' (' . $record['source'] . ')';
        if (in_array($record['source'], $sources, true)) {
            $problems[] = $where . ': source is not unique';
        }
        $sources[] = $record['source'];
        $problems = [
            ...$problems,
            ...logStreamRecordRowProblems($record, $where),
            ...logStreamRecordTokenProblems($record, $where),
            ...logStreamRecordShapeProblems($record, $where),
        ];
    }

    return $problems;
}

/**
 * The keys a record is missing, carries without a name, or types wrongly.
 *
 * @param array<string, mixed> $record One record.
 * @param string $where How the problem names it.
 * @return array<int, string>
 */
function logStreamRecordKeyProblems(array $record, string $where): array
{
    $problems = [];
    foreach (LOG_STREAM_RECORD_KEYS as $key) {
        if (!array_key_exists($key, $record)) {
            $problems[] = $where . ': missing key ' . $key;
        }
    }
    foreach (array_keys($record) as $key) {
        if (!in_array($key, [...LOG_STREAM_RECORD_KEYS, LOG_STREAM_RECORD_OPTIONAL_KEY], true)) {
            $problems[] = $where . ': unknown key ' . $key;
        }
    }
    if ($problems !== []) {
        return $problems;
    }

    $typed = [
        'rows' => is_array($record['rows']),
        'scenario' => is_string($record['scenario']),
        'source' => is_string($record['source']) && $record['source'] !== '',
        'pattern' => $record['pattern'] === null || is_string($record['pattern']),
        'lands' => is_array($record['lands']),
        'never' => is_array($record['never']),
        'nowhere' => is_bool($record['nowhere']),
        'empty' => is_array($record['empty']),
    ];
    foreach ($typed as $key => $wellTyped) {
        if (!$wellTyped) {
            $problems[] = $where . ': key ' . $key . ' has the wrong type';
        }
    }
    if (array_key_exists(LOG_STREAM_RECORD_OPTIONAL_KEY, $record)) {
        $supersedes = $record[LOG_STREAM_RECORD_OPTIONAL_KEY];
        if (!is_string($supersedes) || $supersedes === '') {
            $problems[] = $where . ': key ' . LOG_STREAM_RECORD_OPTIONAL_KEY . ' must be a non-empty string';
        }
    }

    return $problems;
}

/**
 * The rows and the scenario of a record that name nothing on the map.
 *
 * @param array{rows: array<int, mixed>, scenario: string} $record One well-keyed record.
 * @param string $where How the problem names it.
 * @return array<int, string>
 */
function logStreamRecordRowProblems(array $record, string $where): array
{
    $problems = [];
    if ($record['rows'] === []) {
        $problems[] = $where . ': names no map row';
    }
    foreach ($record['rows'] as $row) {
        if (!is_int($row) || $row < 1 || $row > LOG_STREAM_MAP_ROWS) {
            $problems[] = $where . ': map row ' . var_export($row, true) . ' is outside 1..' . LOG_STREAM_MAP_ROWS;
        }
    }
    if (!in_array($record['scenario'], LOG_STREAM_SCENARIOS, true)) {
        $problems[] = $where . ': scenario ' . var_export($record['scenario'], true) . ' is not one of '
            . implode(', ', LOG_STREAM_SCENARIOS);
    }

    return $problems;
}

/**
 * The tokens of a record that are not in the vocabulary, or are in a list they may not be in.
 *
 * @param array{lands: array<int, mixed>, never: array<int, mixed>, empty: array<int, mixed>} $record One well-keyed record.
 * @param string $where How the problem names it.
 * @return array<int, string>
 */
function logStreamRecordTokenProblems(array $record, string $where): array
{
    $problems = [];
    foreach (['lands', 'never', 'empty'] as $list) {
        foreach ($record[$list] as $token) {
            if (!is_string($token) || !in_array($token, LOG_STREAM_TOKENS, true)) {
                $problems[] = $where . ': ' . $list . ' names ' . var_export($token, true) . ', not a stream token';
                continue;
            }
            if ($list !== 'never' && $token === LOG_STREAM_ANY_FILE) {
                $problems[] = $where . ': ' . $list . ' names ' . LOG_STREAM_ANY_FILE . ', which is legal only in never';
            }
            if ($list === 'empty' && (!logStreamTokenIsConcrete($token) || in_array($token, LOG_STREAM_CONTAINER_TOKENS, true))) {
                $problems[] = $where . ': empty names ' . $token . ', which is not one concrete file';
            }
        }
    }

    return $problems;
}

/**
 * The ways a record's halves disagree with each other: a pattern with nothing to land and
 * nothing to forbid, an emptiness record that still names landings, a pattern that does not compile.
 *
 * @param array{pattern: string|null, lands: array<int, mixed>, never: array<int, mixed>, nowhere: bool,
 *     empty: array<int, mixed>} $record One well-keyed record.
 * @param string $where How the problem names it.
 * @return array<int, string>
 */
function logStreamRecordShapeProblems(array $record, string $where): array
{
    $problems = [];
    if ($record['pattern'] === null) {
        if ($record['empty'] === []) {
            $problems[] = $where . ': no pattern and nothing in empty - the record asserts nothing';
        }
        if ($record['lands'] !== [] || $record['never'] !== [] || $record['nowhere']) {
            $problems[] = $where . ': no pattern, so lands, never and nowhere have nothing to apply to';
        }

        return $problems;
    }

    // warning-suppressed: a pattern that does not compile answers false, and that answer is the verdict
    if (@preg_match($record['pattern'], '') === false) {
        $problems[] = $where . ': pattern does not compile: ' . preg_last_error_msg();
    }
    if ($record['never'] === []) {
        $problems[] = $where . ': never is empty - the record cannot catch a stream moving';
    }
    if ($record['nowhere'] && $record['lands'] !== []) {
        $problems[] = $where . ': nowhere and lands cannot both be set';
    }
    if (!$record['nowhere'] && $record['lands'] === []) {
        $problems[] = $where . ': lands is empty without nowhere';
    }

    return $problems;
}

/**
 * The map rows the table covers, ascending and without repeats — what the summary and the doc
 * say the check proves.
 *
 * @param array<int, array{rows: array<int, int>}> $records The validated table.
 * @return array<int, int>
 */
function logStreamRowsCovered(array $records): array
{
    $rows = [];
    foreach ($records as $record) {
        $rows = [...$rows, ...$record['rows']];
    }
    $rows = array_values(array_unique($rows));
    sort($rows);

    return $rows;
}

/**
 * The records of one scenario, in table order.
 *
 * @param array<int, array{scenario: string}> $records The validated table.
 * @param string $scenario One of {@see LOG_STREAM_SCENARIOS}.
 * @return array<int, array<string, mixed>>
 */
function logStreamRecordsOf(array $records, string $scenario): array
{
    return array_values(array_filter(
        $records,
        static fn(array $record): bool => $record['scenario'] === $scenario,
    ));
}

/**
 * The worker indexes a snapshot shows on BOTH a regular and a monopolistic stream.
 *
 * Map row 10 says the monopolistic workers continue the regular indexes rather than restarting
 * them; two streams sharing an index would be two workers written to one file's name. An empty
 * answer is the row holding.
 *
 * @param array<string, string> $snapshot The streams, keyed by name.
 * @return array<int, int> The shared indexes, ascending.
 */
function logStreamSharedWorkerIndexes(array $snapshot): array
{
    $byType = [];
    foreach (array_keys($snapshot) as $name) {
        if (preg_match(LOG_STREAM_WORKER_INDEX_PATTERN, $name, $match) === 1) {
            $byType[$match[1]][] = (int)$match[2];
        }
    }
    $shared = array_values(array_intersect($byType['regular'] ?? [], $byType['monopolistic'] ?? []));
    sort($shared);

    return $shared;
}

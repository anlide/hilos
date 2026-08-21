# Filesystem Watch

Read this before making an agent notice a change on disk it did not make itself,
before adding a "tell the daemon I wrote something" command, or when choosing
between the two engines behind `Hilos\Fs\Watch`.

The watch answers one question — *which of the directories I care about changed
since I last asked* — and answers it the same way on every node. Its machinery is
`framework/backend/Fs/Watch/`; the agent-facing seam is
`Hilos\Core\Agent\DirectoryWatchTrait`.

## Core Rule

A process that owns a directory tree watches it. Do not add a command, a signal
or a page action whose job is to tell the owner that someone else wrote into it.

A poke is correct only for the writers that remember to send it, and it is
exactly the writers nobody thought of — an operator's hand, a cron job, a
restore run, a future subsystem — whose changes leave the index lying. The watch
covers all of them by construction, which is why `backup:refresh-history` was
deleted rather than kept alongside it (HIL-528).

## One Door, Two Engines

`FsWatch::open()` returns an `FsWatchInterface` and decides once, at open, what is
behind it:

- **`InotifyFsWatch`** where `ext-inotify` is loaded and `inotify_init()`
  succeeds — the kernel reports what moved, and draining the queue costs one
  syscall per tick.
- **`PollingFsWatch`** everywhere else — one `stat()` per watched directory per
  second, comparing the DIRECTORY mtime.

**A consumer never branches on which one it got.** That is the point of deciding
here: the alternative is every consumer carrying an "if the extension is loaded"
fork, and the second consumer answering it differently from the first. The
extension is a `composer suggest`, never a `require`: it is installed into
`demo/chat/docker/Dockerfile` and `framework/docker/Dockerfile.test-cli` so the
suite really exercises both engines, and a node without it keeps the same
observable promptness through polling.

## What A Change Means Here

The answer is a **directory**, never a file and never a kind of event. Both
engines can say that a directory's entries moved; only one of them can say how,
and a consumer written against the richer answer would break on the other node.
Whoever needs the detail re-reads the directory.

Neither engine sees everything, and they are blind in different places:

- **A file held open and appended to indefinitely** — a log — is invisible to
  `InotifyFsWatch`, because `IN_MODIFY` is left out of its `EVENT_MASK` on
  purpose: this framework publishes through a temp file and a rename, so
  entry-level events describe a publish completely and a multi-gigabyte archive
  costs four events instead of thousands. A rewrite in place is still reported
  once, when its writer closes the file (`IN_CLOSE_WRITE` is in the mask).
- **A file rewritten in place at all** is invisible to `PollingFsWatch`: a
  directory's mtime moves when an entry is created, removed or renamed, and not
  when an existing entry is overwritten.
- **Anything on a network filesystem that announces nothing** is invisible to
  both.

All of it is covered by the periodic rescan below, not by a richer mask.

## The Ordering Is Exact, Not Approximate

`FsRescanSchedule` decides *when* the consumer re-reads, and
`DirectoryWatchTrait` binds it to four call sites:

| Call | Where the carrier puts it |
|---|---|
| `watchDirectories($dirs)` | `onStart()`, BEFORE the first read — and again as the LAST statement of the read |
| `directoryRescanDue()` | `onTick()`, as the condition of the re-read |
| `discardDirectoryChanges()` | the FIRST statement of the re-read itself |
| `closeDirectoryWatch()` | `onStop()` |

Each position carries its own argument:

- **Watch before the first read.** A read that runs before the watch exists loses
  whatever lands between the two, and nothing asks for it again until the period.
- **Discard, then read.** Discarding says "everything reported so far is about to
  be observed by this very read". A foreign write landing *before* the discard is
  caught by the read itself; one landing *after* it is still queued and wakes the
  next tick. No ordering can lose a change, and the worst case is one redundant
  read — which is what makes self-suppression exact rather than a heuristic
  debounce.
- **Reconcile after every read, not once at start.** The directories a consumer
  owns are state: a backup scope directory is created the first time something is
  written into it and can be removed by hand afterwards. Passing the current set
  on every read is what puts a directory that appeared later under watch.
- **The read lives inside one method.** `BackupAgent::refreshHistory()` is called
  from six places; putting the ordering around each caller is six chances to get
  it wrong.

## Coalescing And The Period

An event never causes a read of its own:

- The first unreported change opens a **fixed** one-second window, and the read
  happens when it closes (`COALESCE_WINDOW_SECONDS`). Fixed rather than
  quiet-until-settled: a settle window starves under a continuous stream and
  fires never, while a fixed one has bounds that can be stated — latency at most
  one window, rate at most one read per window.
- A read happens anyway every five minutes, whatever the engine reported
  (`RESCAN_FLOOR_SECONDS`). This is the backstop for a dropped inotify queue
  (`IN_Q_OVERFLOW`, which also forces "every watched directory changed" plus a
  warning), for a network filesystem, and for the in-place rewrite neither engine
  sees. **The watch buys promptness; the period keeps correctness.**

`FsRescanSchedule` takes `$now` as a parameter in every method, including its
constructor. That is why the one-second window and the five-minute period are
tested in microseconds, with no sleep and no seam over the clock.

## A Log Line Per Pass Is Noise

A consumer that re-reads on a period must log only when something moved. An
unconditional line at five-minute intervals is 288 identical entries a day, and
the interesting one is under them.

## Adding A Consumer

1. `use DirectoryWatchTrait` on the agent that OWNS the tree — the monopoly agent
   where one exists, so exactly one node watches. Nothing extra is needed for a
   cluster.
2. Give it a private method returning the directories it owns *that exist right
   now*. Do not create a directory in order to watch it: the watch is an
   observer, and a directory made just to look at would be indistinguishable from
   storage a writer had prepared.
3. Wire the four calls in the table above.
4. A directory that refuses to be watched costs a warning and nothing else — the
   next reconciliation tries it again. That is why `watch()` raises
   `DirectoryWatchException` rather than logging: the primitive reports, the
   consumer decides, exactly as `FsPath` does with its own failures.

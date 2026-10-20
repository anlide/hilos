// Shared restore-panel selectors and arithmetic for the connection's addressed
// progress frames, used by the maintenance shell and the backup page.
import { type HilosConnection } from './HilosConnection.js'
import {
  BACKUP_RESTORE_PROGRESS_SIGNAL,
  type HilosRestoreStatus,
} from '../protocol/backupSignals.js'
import { createSignal, type ReadonlySignal } from '../state/signal.js'

/** What share of a run one phase takes, and what share is already behind it. */
interface HilosBackupPhaseWeight {
  /** Share of the run completed before this phase (0..1). */
  readonly before: number
  /** Share of the run this phase is expected to take (0..1). */
  readonly weight: number
}

/**
 * How the wall-clock of a run divides between its phases — the frontend copy of the
 * backend `BackupPhase::WEIGHTS` and `RestorePhase::WEIGHTS`.
 *
 * The two sets share one map because a run is either a creation or a restore and the
 * phase values do not collide, so one formula serves both surfaces. The numbers are
 * duplicated rather than sent down with each row: they are code constants on the
 * backend too, and a per-frame copy would put a fixed table on the wire once a second.
 * What keeps the two copies together is the unit suite — the cases in
 * `framework/tests/Unit/BackupProgressTest.php` and the ones here are the same numbers
 * on purpose, so a weight changed on one side reddens the other.
 */
const BACKUP_PHASE_WEIGHTS: Readonly<Record<string, HilosBackupPhaseWeight>> = {
  dumping: { before: 0, weight: 0.7 },
  archiving: { before: 0.7, weight: 0.25 },
  digesting: { before: 0.95, weight: 0.04 },
  publishing: { before: 0.99, weight: 0.01 },
  pending: { before: 0, weight: 0 },
  verifying: { before: 0, weight: 0.05 },
  extracting: { before: 0.05, weight: 0.15 },
  importing: { before: 0.2, weight: 0.55 },
  migrating: { before: 0.75, weight: 0.08 },
  anonymizing: { before: 0.83, weight: 0.1 },
  'installing-triggers': { before: 0.93, weight: 0.02 },
  rehydrating: { before: 0.95, weight: 0.05 },
  succeeded: { before: 1, weight: 0 },
  failed: { before: 1, weight: 0 },
}

/** Percent is reported as a whole number of hundredths of a run (PHP `PERCENT_SCALE`). */
export const PERCENT_SCALE = 100

/** A run still going never shows a full bar, whatever the arithmetic says. */
const MAX_RUNNING_PERCENT = 99

/** Milliseconds in a second, for turning two instants into elapsed seconds. */
const MILLISECONDS_PER_SECOND = 1000

/**
 * The anchors a running backup or restore is drawn from, wherever they arrive.
 *
 * Only the addressed restore frame carries them now: a create run has its numbers
 * counted on the server and delivered ready on the table's bar (HIL-820), while a
 * restore reports through a frame of its own and is drawn from these. The names are the
 * frame's, so a {@link HilosRestoreStatus} is already of this shape.
 */
export interface HilosProgressAnchors {
  /** Current phase value (a `BackupPhase` or a `RestorePhase`), or null when nothing runs. */
  readonly phase: string | null
  /** ISO-8601 instant the current phase began, or null when there is no phase. */
  readonly phaseStartedAt: string | null
  /** ISO-8601 instant the run began, or null when nothing runs. */
  readonly startedAt: string | null
  /** Expected duration of the whole run in seconds, or null when it cannot be estimated. */
  readonly estimatedSeconds: number | null
}

/**
 * Seconds between an ISO-8601 instant and `nowMs`, or null when there is no instant
 * to count from. An unparsable one reads as absent rather than as NaN seconds: a
 * malformed timestamp must leave the surface without a number, not with a broken one.
 *
 * @param instant The ISO-8601 instant to measure from, or null.
 * @param nowMs The current epoch milliseconds the caller counts against.
 */
export function elapsedSecondsSince(
  instant: string | null,
  nowMs: number,
): number | null {
  if (instant === null) {
    return null
  }
  const startedMs = Date.parse(instant)

  return Number.isNaN(startedMs)
    ? null
    : (nowMs - startedMs) / MILLISECONDS_PER_SECOND
}

/**
 * How far along a run is, in whole percent — the frontend half of the backend
 * `BackupProgress::percent()`, arithmetic for arithmetic.
 *
 * The phases before the current one count whole; the current one contributes the
 * share of its own budget that has already elapsed, capped at that budget. The result
 * is clamped to the current phase's own span, so a browser clock running against the
 * server's moves the bar to the edge of that phase rather than somewhere absurd.
 *
 * A run whose duration cannot be estimated has no percentage at all rather than an
 * invented one — the surfaces then show the phase name and an indeterminate bar.
 *
 * @param anchors The progress anchors of the run.
 * @param nowMs The current epoch milliseconds (from {@link createBackupProgressClock}).
 */
export function backupProgressPercent(
  anchors: HilosProgressAnchors,
  nowMs: number,
): number | null {
  const phase =
    anchors.phase === null ? undefined : BACKUP_PHASE_WEIGHTS[anchors.phase]
  if (phase === undefined) {
    return null
  }
  if (anchors.estimatedSeconds === null || anchors.estimatedSeconds <= 0) {
    return null
  }
  if (phase.before >= 1) {
    return PERCENT_SCALE
  }

  // No instant for the phase means no time spent in it yet, which puts the bar at the
  // phase's own floor — the honest reading of "this phase has just been announced".
  const phaseElapsed = elapsedSecondsSince(anchors.phaseStartedAt, nowMs) ?? 0
  const phaseBudget = anchors.estimatedSeconds * phase.weight
  const withinPhase =
    phaseBudget > 0 ? Math.min(1, Math.max(0, phaseElapsed) / phaseBudget) : 0

  const reached = Math.floor(
    (phase.before + phase.weight * withinPhase) * PERCENT_SCALE,
  )
  const floor = Math.floor(phase.before * PERCENT_SCALE)
  const ceiling = Math.min(
    MAX_RUNNING_PERCENT,
    Math.floor((phase.before + phase.weight) * PERCENT_SCALE),
  )

  return Math.max(floor, Math.min(ceiling, reached))
}

/**
 * What the surfaces print next to the phase to say how much longer the run has — the
 * frontend wording of the backend `BackupProgress::remainingSeconds()`, which the CLI
 * monitor prints in the same two forms.
 *
 * A run that outlived its estimate is told so in words rather than as a negative
 * number or a zero that never moves: both of those read as "about to finish", which
 * is the one thing that is certainly not true. A run with no estimate says nothing.
 *
 * @param anchors The progress anchors of the run.
 * @param nowMs The current epoch milliseconds (from {@link createBackupProgressClock}).
 */
export function formatBackupEta(
  anchors: HilosProgressAnchors,
  nowMs: number,
): string {
  const totalElapsed = elapsedSecondsSince(anchors.startedAt, nowMs)
  if (
    totalElapsed === null ||
    anchors.estimatedSeconds === null ||
    anchors.estimatedSeconds <= 0
  ) {
    return ''
  }

  const remaining = Math.ceil(anchors.estimatedSeconds - totalElapsed)

  return remaining > 0 ? `~${remaining}s left` : 'taking longer than usual'
}

/**
 * The caption under a progress bar: the phase, how far along it is, and how much
 * longer it has — `importing · 62% · ~40s left`, with each part dropped when the run
 * cannot say it.
 *
 * Composed here rather than in the views because the three of them render the same
 * cell: a caption assembled per framework drifts a separator at a time, and this one
 * is read while an operator waits on a destructive operation.
 *
 * A run that has not announced a phase yet keeps the old wording — the bar has always
 * said "In progress" before there was anything more precise to say — and it is told
 * no time either: a run with no phase may not have begun at all (a create waits its
 * turn behind the one already going), and counting down from acceptance would promise
 * an end to something that has not started.
 *
 * @param anchors The progress anchors of the run.
 * @param nowMs The current epoch milliseconds (from {@link createBackupProgressClock}).
 */
export function formatBackupProgressLabel(
  anchors: HilosProgressAnchors,
  nowMs: number,
): string {
  const percent = backupProgressPercent(anchors, nowMs)
  if (percent === null) {
    return anchors.phase ?? 'In progress'
  }

  const parts = [anchors.phase ?? 'In progress', `${percent}%`]
  const eta = formatBackupEta(anchors, nowMs)
  if (eta !== '') {
    parts.push(eta)
  }

  return parts.join(' · ')
}

/** The live restore status a backup view renders while a restore of its own runs. */
export interface HilosRestoreProgress {
  /** The latest frame this connection was sent, or null until the first one arrives. */
  readonly status: ReadonlySignal<HilosRestoreStatus | null>
  /** Start listening for frames — call on mount. */
  start(): void
  /** Stop listening — call on unmount. */
  dispose(): void
}

/**
 * The live restore status for the connection that asked for a restore.
 *
 * It exists because the table cannot report during a restore: the node is frozen, the
 * page's own agent is stopped, and the only channel left is the addressed frame the
 * backup agent sends to the initiator. Frames arrive on the connection rather than in
 * the page scope for the same reason — page scopes are fed by the machinery that is
 * down for the length of the operation.
 *
 * Another admin's restore is not shown here: the frames are addressed, so a tab that
 * did not start one simply never receives any and keeps a null status. Since HIL-655
 * they are addressed to the browser SESSION rather than to one socket, so every tab of
 * the admin who asked receives them — including the one showing the maintenance
 * surface, which is why this takes a bare connection rather than the backup page's
 * context: the shell holds no page scope and no action lifecycle, and this never
 * needed either.
 *
 * @param connection The connection the frames arrive on.
 */
export function createHilosRestoreProgress(
  connection: HilosConnection,
): HilosRestoreProgress {
  const status = createSignal<HilosRestoreStatus | null>(null)
  const teardown: Array<() => void> = []

  return {
    status,
    start() {
      teardown.push(
        connection.on('projectSignal', (signal) => {
          if (signal.type === BACKUP_RESTORE_PROGRESS_SIGNAL) {
            // Validated against restoreProgressSchema at the parse boundary; this cast is
            // the declared typed selector for that schema's output.
            status.set(signal.data as HilosRestoreStatus)
          }
        }),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * The phase line of the restore panel — `Restore 2026-08-24-full · importing`.
 *
 * Worded here rather than in the three shells for the reason every caption in this
 * module is: an operator reads it while a destructive operation runs, and three
 * templates drift a separator at a time. The phase value is the backend's own
 * (`RestorePhase`), the terminal ones included, so the line keeps saying something
 * true after the run ends and the outcome line below it takes over.
 *
 * A frame that names no archive or no phase drops that half rather than printing a
 * placeholder: the panel is the only thing a shuttered tab has to go on, and an
 * invented identifier there is worse than a shorter sentence.
 *
 * @param status The latest restore frame this connection received.
 */
export function formatRestorePhaseLine(status: HilosRestoreStatus): string {
  const named = ['Restore', status.backupId].filter(
    (part) => part !== null && part !== '',
  )

  return status.phase === null || status.phase === ''
    ? named.join(' ')
    : `${named.join(' ')} · ${status.phase}`
}

/**
 * The outcome line under {@link formatRestorePhaseLine}, empty while the run is still
 * going: a restore in flight has no outcome, and a sentence that appears before there
 * is one would be read as the answer.
 *
 * The failure wording carries the two facts a shuttered tab cannot find anywhere else
 * — whether the database was already being replaced, and whether the node stays closed
 * because not every process re-read it. Neither is actionable from this screen, and
 * that is precisely why they are said here: the person reading it is the one who will
 * have to act from a terminal.
 *
 * The success wording names no button, deliberately. This line is shown on the
 * maintenance placeholder as well as on the backup page, and the placeholder has no
 * reopen block to point at — a sentence promising one there would be a promise about a
 * control the reader cannot see. What it says instead is the state, which is true on
 * both surfaces: the system is closed to everyone but this browser until it is reopened.
 *
 * @param status The latest restore frame this connection received.
 */
export function formatRestoreOutcomeLine(status: HilosRestoreStatus): string {
  if (status.outcome === 'success') {
    return 'Restored. The system stays closed to everyone but this browser until it is reopened.'
  }
  if (status.outcome !== 'error') {
    return ''
  }

  const sentences = [status.failureReason ?? 'The restore failed.']
  if (status.databaseTouched) {
    sentences.push('The database was already being replaced when this failed.')
  }
  if (!status.rehydrateComplete) {
    sentences.push(
      'The system stays closed until an operator reopens it from the CLI.',
    )
  }

  return sentences.join(' ')
}

/** The shared clock a backup view redraws its progress from. */
export interface HilosBackupProgressClock {
  /** Epoch milliseconds, republished once a second while the clock lives. */
  readonly now: ReadonlySignal<number>
  /** Stop the tick — call on unmount. */
  dispose(): void
}

/**
 * The one-second clock the backup page's progress bars are redrawn from.
 *
 * The restore panel is what reads it: a restore's phase anchors arrive once per phase
 * on its addressed frame, and everything between them is arithmetic against "now", so
 * something has to re-render on its own. A create run needs none of this — its share
 * and its time left arrive counted on the table's bar (HIL-820).
 *
 * The tick keeps running while a page shows no run at all. That is deliberate: a
 * ticker that started and stopped with the restore would have to be owned by the
 * restore rather than the page, and a signal republished once a second with no
 * subscriber costs nothing to nobody.
 */
export function createBackupProgressClock(): HilosBackupProgressClock {
  const now = createSignal(Date.now())
  const timer = setInterval(() => now.set(Date.now()), MILLISECONDS_PER_SECOND)

  return {
    now,
    dispose() {
      clearInterval(timer)
    },
  }
}

import { describe, expect, it } from 'vitest'

import {
  backupMigrationBehind,
  backupMigrationNotes,
  backupProgressPercent,
  backupRowAnchors,
  formatBackupChecksum,
  formatBackupShipping,
  formatBackupDuration,
  formatBackupEta,
  formatBackupProgressLabel,
  formatBackupSize,
  formatRestoreCliCommand,
  hasBackupFailureDetail,
  hasRestoreOutcome,
  isBackupChecksumMismatch,
  isBackupShipFailed,
  isBackupMigrationRefused,
  isBackupRestorable,
  resolveHilosBackupRow,
  type HilosBackupRow,
  type HilosProgressAnchors,
} from '../../src/admin/backup/hilosBackups.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'

function row(overrides: Partial<HilosBackupRow> = {}): HilosBackupRow {
  return {
    id: 'b1',
    createdAt: '2026-07-20T10:00:00+00:00',
    env: 'dev',
    scope: 'full',
    sizeBytes: 2048,
    durationSeconds: 7,
    keep: false,
    status: 'success',
    finished: true,
    failureReason: null,
    checksumState: 'none',
    verifiedAt: null,
    shipState: 'none',
    shippedAt: null,
    shipError: null,
    restorePhase: null,
    restoreOutcome: null,
    restoreFinishedAt: null,
    restoreFailureReason: null,
    restoreDatabaseTouched: false,
    restoreMigrationDecision: null,
    restoreMigrationBehind: null,
    restoreMigrationNotice: null,
    progressPhase: null,
    progressPhaseStartedAt: null,
    progressEstimatedSeconds: null,
    ...overrides,
  }
}

function backupTableRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { backup: slot } }
}

describe('formatBackupDuration', () => {
  it('reports a sub-second run as 0s, not as missing data', () => {
    expect(formatBackupDuration(row({ durationSeconds: 0 }))).toBe('0s')
  })

  it('reports seconds under a minute', () => {
    expect(formatBackupDuration(row({ durationSeconds: 7 }))).toBe('7s')
  })

  it('splits a longer run into minutes and seconds', () => {
    expect(formatBackupDuration(row({ durationSeconds: 125 }))).toBe('2m 5s')
  })

  it('dashes only the in-progress row, whose duration is not known yet', () => {
    expect(
      formatBackupDuration(row({ finished: false, durationSeconds: 0 })),
    ).toBe('—')
  })

  it('reports a failed run by the time it burned before failing', () => {
    expect(
      formatBackupDuration(
        row({ finished: null, status: 'error', durationSeconds: 3 }),
      ),
    ).toBe('3s')
  })
})

describe('formatBackupSize', () => {
  it('scales bytes to the largest fitting unit', () => {
    expect(formatBackupSize(row({ sizeBytes: 2048 }))).toBe('2.0 KB')
  })

  it('leaves bytes unscaled', () => {
    expect(formatBackupSize(row({ sizeBytes: 512 }))).toBe('512 B')
  })

  it('dashes a row with no archive — in progress, or a failure', () => {
    expect(formatBackupSize(row({ finished: false, sizeBytes: 0 }))).toBe('—')
    expect(formatBackupSize(row({ finished: null, sizeBytes: 0 }))).toBe('—')
  })
})

describe('resolveHilosBackupRow', () => {
  it('reads the checksum state and the verification instant from the slot', () => {
    const resolved = resolveHilosBackupRow(
      backupTableRow('b1', {
        checksumState: 'verified',
        verifiedAt: '2026-08-02T06:07:08+00:00',
      }),
    )

    expect(resolved.checksumState).toBe('verified')
    expect(resolved.verifiedAt).toBe('2026-08-02T06:07:08+00:00')
  })

  it('reads the copy state, its instant and its error from the slot', () => {
    const resolved = resolveHilosBackupRow(
      backupTableRow('b1', {
        shipState: 'failed',
        shippedAt: '2026-08-15T06:07:08+00:00',
        shipError: 'ssh: connect timed out',
      }),
    )

    expect(resolved.shipState).toBe('failed')
    expect(resolved.shippedAt).toBe('2026-08-15T06:07:08+00:00')
    expect(resolved.shipError).toBe('ssh: connect timed out')
  })

  it('reads an unknown or absent copy state as none', () => {
    // Same reasoning as the checksum state: a row that cannot say a copy is owed must
    // not show the operator one that is pending forever.
    expect(resolveHilosBackupRow(backupTableRow('b1', {})).shipState).toBe(
      'none',
    )
    expect(
      resolveHilosBackupRow(backupTableRow('b1', { shipState: 'sent' }))
        .shipState,
    ).toBe('none')
    expect(resolveHilosBackupRow(backupTableRow('b1', {})).shippedAt).toBeNull()
    expect(resolveHilosBackupRow(backupTableRow('b1', {})).shipError).toBeNull()
  })

  it('reads an unknown or absent checksum state as none', () => {
    // A row that cannot say it was checked must not render as if it were: a legacy
    // payload has no key at all, and a newer backend could send a state this build
    // does not know.
    expect(resolveHilosBackupRow(backupTableRow('b1', {})).checksumState).toBe(
      'none',
    )
    expect(
      resolveHilosBackupRow(backupTableRow('b1', { checksumState: 'weird' }))
        .checksumState,
    ).toBe('none')
    expect(
      resolveHilosBackupRow(backupTableRow('b1', {})).verifiedAt,
    ).toBeNull()
  })

  it('reads a failure reason from the slot', () => {
    const resolved = resolveHilosBackupRow(
      backupTableRow('b1', {
        status: 'error',
        finished: null,
        failureReason: 'timed out after 30s',
      }),
    )

    expect(resolved.failureReason).toBe('timed out after 30s')
  })

  it('reads a missing reason as null', () => {
    expect(
      resolveHilosBackupRow(backupTableRow('b1', { status: 'success' }))
        .failureReason,
    ).toBeNull()
  })

  it('reads an empty-string reason as null, so "no detail" is unambiguous', () => {
    expect(
      resolveHilosBackupRow(backupTableRow('b1', { failureReason: '' }))
        .failureReason,
    ).toBeNull()
  })

  it('reads the five restore fields of the archive that was replayed', () => {
    const resolved = resolveHilosBackupRow(
      backupTableRow('b1', {
        restorePhase: 'failed',
        restoreOutcome: 'error',
        restoreFinishedAt: '2026-08-15T10:34:00+00:00',
        restoreFailureReason: 'import failed',
        restoreDatabaseTouched: true,
      }),
    )

    expect(resolved.restorePhase).toBe('failed')
    expect(resolved.restoreOutcome).toBe('error')
    expect(resolved.restoreFinishedAt).toBe('2026-08-15T10:34:00+00:00')
    expect(resolved.restoreFailureReason).toBe('import failed')
    expect(resolved.restoreDatabaseTouched).toBe(true)
  })

  it('reads the migration verdict the backend judged this archive with', () => {
    const resolved = resolveHilosBackupRow(
      backupTableRow('b1', {
        restoreMigrationDecision: 'refuse',
        restoreMigrationBehind: null,
        restoreMigrationNotice:
          'connection 0: archive at migration 44, code expects 40 (4 ahead);' +
          ' there is no downgrade path',
      }),
    )

    expect(resolved.restoreMigrationDecision).toBe('refuse')
    expect(resolved.restoreMigrationBehind).toBeNull()
    expect(resolved.restoreMigrationNotice).toContain('no downgrade path')
  })

  it('reads an archive with nothing to say about its levels as saying nothing', () => {
    const resolved = resolveHilosBackupRow(backupTableRow('b1', {}))

    expect(resolved.restoreMigrationDecision).toBeNull()
    expect(resolved.restoreMigrationBehind).toBeNull()
    expect(resolved.restoreMigrationNotice).toBeNull()
  })

  it('reads the progress anchors of the run in progress from the slot', () => {
    const resolved = resolveHilosBackupRow(
      backupTableRow('__running__', {
        status: 'running',
        finished: false,
        progressPhase: 'dumping',
        progressPhaseStartedAt: '2026-08-15T11:59:25+00:00',
        progressEstimatedSeconds: 100,
      }),
    )

    expect(resolved.progressPhase).toBe('dumping')
    expect(resolved.progressPhaseStartedAt).toBe('2026-08-15T11:59:25+00:00')
    expect(resolved.progressEstimatedSeconds).toBe(100)
  })

  it('reads a stored archive as carrying no progress anchors', () => {
    // A run that cannot be estimated sends a null rather than a zero: zero seconds left
    // is a claim about the run, and "we have no history for this" is not one.
    const resolved = resolveHilosBackupRow(backupTableRow('b1', {}))

    expect(resolved.progressPhase).toBeNull()
    expect(resolved.progressPhaseStartedAt).toBeNull()
    expect(resolved.progressEstimatedSeconds).toBeNull()
  })

  it('reads an archive nobody restored as carrying no restore at all', () => {
    const resolved = resolveHilosBackupRow(backupTableRow('b1', {}))

    expect(resolved.restorePhase).toBeNull()
    expect(resolved.restoreOutcome).toBeNull()
    expect(resolved.restoreFinishedAt).toBeNull()
    expect(resolved.restoreFailureReason).toBeNull()
    // Not "unknown": a row that says nothing about a restore says nothing about its
    // damage either, and the flag only ever means "this run had begun writing".
    expect(resolved.restoreDatabaseTouched).toBe(false)
  })
})

// The progress cases below are the frontend half of
// `framework/tests/Unit/BackupProgressTest.php`: the same phases, the same
// hundred-second run, and the same expected numbers. That pairing is the only thing
// holding the two implementations of one formula together, so a case changed here
// belongs in the PHP suite as well.

/** A run long enough for one weight point to be one percent of it. */
const RUN_SECONDS = 100

/** The instant every progress case measures against. */
const NOW_MS = Date.parse('2026-08-15T12:00:00+00:00')

/** Milliseconds in a second, for turning a case's elapsed seconds into an instant. */
const SECOND_MS = 1000

function anchors(
  phase: string | null,
  phaseElapsedSeconds: number,
  overrides: Partial<HilosProgressAnchors> = {},
): HilosProgressAnchors {
  return {
    phase,
    phaseStartedAt: new Date(
      NOW_MS - phaseElapsedSeconds * SECOND_MS,
    ).toISOString(),
    startedAt: new Date(NOW_MS - phaseElapsedSeconds * SECOND_MS).toISOString(),
    estimatedSeconds: RUN_SECONDS,
    ...overrides,
  }
}

describe('backupProgressPercent', () => {
  it('starts each create phase at the weight behind it', () => {
    expect(backupProgressPercent(anchors('dumping', 0), NOW_MS)).toBe(0)
    expect(backupProgressPercent(anchors('archiving', 0), NOW_MS)).toBe(70)
    expect(backupProgressPercent(anchors('digesting', 0), NOW_MS)).toBe(95)
    expect(backupProgressPercent(anchors('publishing', 0), NOW_MS)).toBe(99)
  })

  it('starts each restore phase at the weight behind it', () => {
    expect(backupProgressPercent(anchors('verifying', 0), NOW_MS)).toBe(0)
    expect(backupProgressPercent(anchors('extracting', 0), NOW_MS)).toBe(5)
    expect(backupProgressPercent(anchors('importing', 0), NOW_MS)).toBe(20)
    expect(backupProgressPercent(anchors('migrating', 0), NOW_MS)).toBe(75)
    expect(backupProgressPercent(anchors('anonymizing', 0), NOW_MS)).toBe(85)
    expect(backupProgressPercent(anchors('rehydrating', 0), NOW_MS)).toBe(95)
  })

  it('fills a phase from its own share of the run', () => {
    // Dumping owns 70 of the 100 seconds, so half of it is 35 percent of the whole run.
    expect(backupProgressPercent(anchors('dumping', 35), NOW_MS)).toBe(35)
    // Importing owns 55 seconds and starts at 20 percent: a fifth of it lands at 31.
    expect(backupProgressPercent(anchors('importing', 11), NOW_MS)).toBe(31)
  })

  it('stops a phase that outlives its share at its own ceiling', () => {
    expect(backupProgressPercent(anchors('dumping', 5000), NOW_MS)).toBe(70)
    expect(backupProgressPercent(anchors('importing', 5000), NOW_MS)).toBe(75)
  })

  it('never fills the bar while the run is still going', () => {
    // Publishing ends the run arithmetically, but only a terminal phase shows a full bar.
    expect(backupProgressPercent(anchors('publishing', 5000), NOW_MS)).toBe(99)
    expect(backupProgressPercent(anchors('succeeded', 0), NOW_MS)).toBe(100)
    expect(backupProgressPercent(anchors('failed', 0), NOW_MS)).toBe(100)
  })

  it('reports no percentage at all when the run cannot be estimated', () => {
    expect(
      backupProgressPercent(
        anchors('dumping', 10, { estimatedSeconds: null }),
        NOW_MS,
      ),
    ).toBeNull()
    expect(
      backupProgressPercent(
        anchors('dumping', 10, { estimatedSeconds: 0 }),
        NOW_MS,
      ),
    ).toBeNull()
  })

  it('reports no percentage for a phase this frontend does not know', () => {
    // A value from a newer backend draws no bar rather than an arbitrary one.
    expect(backupProgressPercent(anchors('transmuting', 10), NOW_MS)).toBeNull()
    expect(backupProgressPercent(anchors(null, 0), NOW_MS)).toBeNull()
  })

  it('puts a phase with no instant at its own floor', () => {
    expect(
      backupProgressPercent(
        anchors('importing', 0, { phaseStartedAt: null }),
        NOW_MS,
      ),
    ).toBe(20)
  })
})

describe('formatBackupEta', () => {
  it('counts down what is left of the estimate', () => {
    expect(formatBackupEta(anchors('dumping', 40), NOW_MS)).toBe('~60s left')
  })

  it('says an overrun in words rather than as a number that reads as almost done', () => {
    expect(formatBackupEta(anchors('archiving', 140), NOW_MS)).toBe(
      'taking longer than usual',
    )
  })

  it('says nothing at all when the run cannot be estimated', () => {
    expect(
      formatBackupEta(
        anchors('dumping', 40, { estimatedSeconds: null }),
        NOW_MS,
      ),
    ).toBe('')
    expect(
      formatBackupEta(anchors('dumping', 40, { startedAt: null }), NOW_MS),
    ).toBe('')
  })
})

describe('formatBackupProgressLabel', () => {
  it('names the phase, how far along it is, and how much longer it has', () => {
    expect(formatBackupProgressLabel(anchors('importing', 11), NOW_MS)).toBe(
      'importing · 31% · ~89s left',
    )
  })

  it('drops the parts a run without an estimate cannot say', () => {
    expect(
      formatBackupProgressLabel(
        anchors('importing', 11, { estimatedSeconds: null }),
        NOW_MS,
      ),
    ).toBe('importing')
  })

  it('keeps the old wording for a run that has announced no phase', () => {
    expect(formatBackupProgressLabel(anchors(null, 0), NOW_MS)).toBe(
      'In progress',
    )
  })
})

describe('backupRowAnchors', () => {
  it('draws the in-progress row from its own creation instant', () => {
    const anchored = backupRowAnchors(
      row({
        finished: false,
        status: 'running',
        createdAt: '2026-08-15T11:59:20+00:00',
        progressPhase: 'dumping',
        progressPhaseStartedAt: '2026-08-15T11:59:25+00:00',
        progressEstimatedSeconds: RUN_SECONDS,
      }),
    )

    expect(backupProgressPercent(anchored, NOW_MS)).toBe(35)
    expect(formatBackupEta(anchored, NOW_MS)).toBe('~60s left')
  })

  it('reads a stored archive as a run that is not happening', () => {
    const anchored = backupRowAnchors(row())

    expect(anchored.phase).toBeNull()
    expect(backupProgressPercent(anchored, NOW_MS)).toBeNull()
    expect(formatBackupEta(anchored, NOW_MS)).toBe('')
  })
})

describe('isBackupRestorable', () => {
  it('is true for a completed backup whose archive still matches its digest', () => {
    expect(isBackupRestorable(row({ finished: true }))).toBe(true)
    expect(
      isBackupRestorable(row({ finished: true, checksumState: 'verified' })),
    ).toBe(true)
  })

  it('is false for an archive known to differ from its digest', () => {
    expect(
      isBackupRestorable(row({ finished: true, checksumState: 'mismatch' })),
    ).toBe(false)
  })

  it('is false for an archive the migration gate refuses', () => {
    expect(
      isBackupRestorable(
        row({ finished: true, restoreMigrationDecision: 'refuse' }),
      ),
    ).toBe(false)
  })

  it('is false for a failure and for the in-progress row', () => {
    expect(isBackupRestorable(row({ finished: null, status: 'error' }))).toBe(
      false,
    )
    expect(
      isBackupRestorable(row({ finished: false, status: 'running' })),
    ).toBe(false)
  })
})

describe('isBackupMigrationRefused', () => {
  it('is true only for the verdict that refuses, never for an allowed one', () => {
    expect(
      isBackupMigrationRefused(row({ restoreMigrationDecision: 'refuse' })),
    ).toBe(true)
    expect(
      isBackupMigrationRefused(row({ restoreMigrationDecision: 'allow' })),
    ).toBe(false)
    // A row from a build that judged nothing must not read as refused: it would take
    // the button away from every archive on the list.
    expect(isBackupMigrationRefused(row())).toBe(false)
  })
})

describe('backupMigrationBehind', () => {
  it('answers the count only where there is one to show', () => {
    expect(backupMigrationBehind(row({ restoreMigrationBehind: 8 }))).toBe(8)
    expect(backupMigrationBehind(row())).toBeNull()
  })
})

describe('backupMigrationNotes', () => {
  it('splits the newline-joined notice into one line per connection', () => {
    const notes = backupMigrationNotes(
      row({
        restoreMigrationNotice:
          'connection 0: archive at migration 32, code expects 40;' +
          ' 8 migration(s) will be applied after the import\n' +
          'connection 1: archive records no migration level' +
          ' (sidecar predates the field); restoring without the compatibility check',
      }),
    )

    // The gate's own refusal carries a semicolon inside one sentence, which is why the
    // wire joins on a newline and this splits on one.
    expect(notes).toHaveLength(2)
    expect(notes[0]).toContain('8 migration(s) will be applied')
    expect(notes[1]).toContain('sidecar predates the field')
  })

  it('is empty for an archive with nothing to say, so the block is not rendered', () => {
    expect(backupMigrationNotes(row())).toEqual([])
    expect(backupMigrationNotes(row({ restoreMigrationNotice: '' }))).toEqual(
      [],
    )
  })
})

describe('hasRestoreOutcome', () => {
  it('is true only once a restore of this archive has ended', () => {
    expect(hasRestoreOutcome(row({ restoreOutcome: 'success' }))).toBe(true)
    expect(hasRestoreOutcome(row({ restorePhase: 'importing' }))).toBe(false)
    expect(hasRestoreOutcome(row())).toBe(false)
  })
})

describe('formatRestoreCliCommand', () => {
  it('substitutes the archive and its scope, so nothing is retyped', () => {
    expect(
      formatRestoreCliCommand(
        row({ id: '2026-08-15_10-30-00', scope: 'full' }),
      ),
    ).toBe('php cli.php backup:restore 2026-08-15_10-30-00 --scope=full --yes')
  })

  it('keeps the configured entry path out of the instruction', () => {
    // Where the script lives inside the container says nothing about how an operator
    // reaches the machine, so the command names the canonical entry and not the env value.
    expect(formatRestoreCliCommand(row())).toContain('php cli.php')
  })

  it('leaves a placeholder rather than guessing a scope the record never named', () => {
    expect(formatRestoreCliCommand(row({ scope: null }))).toContain(
      '--scope=<scope>',
    )
  })
})

describe('formatBackupChecksum', () => {
  it('dashes a backup that carries no digest at all', () => {
    // Every backup written before checksums existed reads this way; it is "nothing to
    // check", and must not look like a failed check.
    expect(formatBackupChecksum(row({ checksumState: 'none' }))).toBe('—')
  })

  it('reports a recorded digest nobody has checked yet', () => {
    expect(formatBackupChecksum(row({ checksumState: 'present' }))).toBe(
      'present',
    )
  })

  it('shows the day a verified archive was checked, not the full instant', () => {
    expect(
      formatBackupChecksum(
        row({
          checksumState: 'verified',
          verifiedAt: '2026-08-02T06:07:08+00:00',
        }),
      ),
    ).toBe('✓ 2026-08-02')
  })

  it('still marks a verified archive whose instant went missing', () => {
    expect(
      formatBackupChecksum(
        row({ checksumState: 'verified', verifiedAt: null }),
      ),
    ).toBe('✓')
  })

  it('shouts about an archive that did not match', () => {
    expect(formatBackupChecksum(row({ checksumState: 'mismatch' }))).toBe(
      'MISMATCH',
    )
  })
})

describe('isBackupChecksumMismatch', () => {
  it('is true only for a mismatching archive', () => {
    expect(isBackupChecksumMismatch(row({ checksumState: 'mismatch' }))).toBe(
      true,
    )
    expect(isBackupChecksumMismatch(row({ checksumState: 'verified' }))).toBe(
      false,
    )
    expect(isBackupChecksumMismatch(row({ checksumState: 'none' }))).toBe(false)
  })
})

describe('formatBackupShipping', () => {
  it('shows a dash when the installation ships nowhere', () => {
    expect(formatBackupShipping(row({ shipState: 'none' }))).toBe('—')
  })

  it('shows a waiting marker while a copy is owed', () => {
    expect(formatBackupShipping(row({ shipState: 'pending' }))).toBe('pending')
  })

  it('shows the date a copy landed', () => {
    expect(
      formatBackupShipping(
        row({ shipState: 'shipped', shippedAt: '2026-08-16T06:07:08+00:00' }),
      ),
    ).toBe('✓ 2026-08-16')
  })

  it('shows a bare tick for a copy that landed without an instant', () => {
    expect(
      formatBackupShipping(row({ shipState: 'shipped', shippedAt: null })),
    ).toBe('✓')
  })

  it('shouts when the last attempt did not make it', () => {
    expect(formatBackupShipping(row({ shipState: 'failed' }))).toBe('FAILED')
  })
})

describe('isBackupShipFailed', () => {
  it('is true only for a copy that did not make it', () => {
    expect(isBackupShipFailed(row({ shipState: 'failed' }))).toBe(true)
    expect(isBackupShipFailed(row({ shipState: 'shipped' }))).toBe(false)
    expect(isBackupShipFailed(row({ shipState: 'pending' }))).toBe(false)
    expect(isBackupShipFailed(row({ shipState: 'none' }))).toBe(false)
  })
})

describe('hasBackupFailureDetail', () => {
  it('is true only for a failed backup that carries a reason', () => {
    expect(
      hasBackupFailureDetail(
        row({ finished: null, status: 'error', failureReason: 'boom' }),
      ),
    ).toBe(true)
  })

  it('is false for a failure with no stored reason (a legacy record)', () => {
    expect(
      hasBackupFailureDetail(
        row({ finished: null, status: 'error', failureReason: null }),
      ),
    ).toBe(false)
  })

  it('is false for a successful backup', () => {
    expect(hasBackupFailureDetail(row({ finished: true }))).toBe(false)
  })

  it('is false for the in-progress row', () => {
    expect(
      hasBackupFailureDetail(row({ finished: false, status: 'running' })),
    ).toBe(false)
  })
})

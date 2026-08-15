import { describe, expect, it } from 'vitest'

import {
  formatBackupChecksum,
  formatBackupDuration,
  formatBackupSize,
  formatRestoreCliCommand,
  hasBackupFailureDetail,
  hasRestoreOutcome,
  isBackupChecksumMismatch,
  isBackupRestorable,
  resolveHilosBackupRow,
  type HilosBackupRow,
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
    restorePhase: null,
    restoreOutcome: null,
    restoreFinishedAt: null,
    restoreFailureReason: null,
    restoreDatabaseTouched: false,
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

  it('is false for a failure and for the in-progress row', () => {
    expect(isBackupRestorable(row({ finished: null, status: 'error' }))).toBe(
      false,
    )
    expect(
      isBackupRestorable(row({ finished: false, status: 'running' })),
    ).toBe(false)
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

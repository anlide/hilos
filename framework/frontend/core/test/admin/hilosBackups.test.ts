import { describe, expect, it } from 'vitest'

import {
  formatBackupChecksum,
  formatBackupDuration,
  formatBackupSize,
  hasBackupFailureDetail,
  isBackupChecksumMismatch,
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

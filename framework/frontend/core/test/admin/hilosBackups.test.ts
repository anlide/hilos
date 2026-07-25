import { describe, expect, it } from 'vitest'

import {
  formatBackupDuration,
  formatBackupSize,
  type HilosBackupRow,
} from '../../src/admin/backup/hilosBackups.js'

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
    ...overrides,
  }
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

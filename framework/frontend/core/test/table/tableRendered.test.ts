import { describe, expect, it } from 'vitest'
import { HILOS_TABLE_ACTIONS_KEY } from '../../src/table/hilosTableColumn.js'
import { hilosTableRenderedKeys } from '../../src/table/tableRendered.js'

describe('hilosTableRenderedKeys', () => {
  it('names every column by its key, in declaration order', () => {
    expect(
      hilosTableRenderedKeys([
        { key: 'name', label: 'Name' },
        { key: 'presence', label: 'Presence' },
      ]),
    ).toEqual(['name', 'presence'])
  })

  it('adds the fields a cell reads beyond its own key', () => {
    expect(
      hilosTableRenderedKeys([
        { key: 'key', label: 'Key' },
        {
          key: 'value',
          label: 'Value',
          reads: ['valueSource', 'defaultReferenceKey'],
        },
      ]),
    ).toEqual(['key', 'value', 'valueSource', 'defaultReferenceKey'])
  })

  it('takes nothing from the actions column but what it reads', () => {
    // The virtual key names no field of the row: counted in, it would compare a field
    // no row carries, and a server that found none would be comparing nothing real.
    expect(
      hilosTableRenderedKeys([
        { key: 'name', label: 'Name' },
        { key: HILOS_TABLE_ACTIONS_KEY, label: '', reads: ['status'] },
      ]),
    ).toEqual(['name', 'status'])
    expect(
      hilosTableRenderedKeys([
        { key: 'name', label: 'Name' },
        { key: HILOS_TABLE_ACTIONS_KEY, label: '', reads: [] },
      ]),
    ).toEqual(['name'])
  })

  it('names a field once however many cells read it', () => {
    expect(
      hilosTableRenderedKeys([
        { key: 'createdAt', label: 'Date', reads: ['holderNode'] },
        { key: 'keep', label: 'Keep', reads: ['finished', 'holderNode'] },
        {
          key: HILOS_TABLE_ACTIONS_KEY,
          label: '',
          reads: ['finished', 'keep'],
        },
      ]),
    ).toEqual(['createdAt', 'holderNode', 'keep', 'finished'])
  })

  it('counts a column drawn only in the expanded panel or kept out of the card', () => {
    expect(
      hilosTableRenderedKeys([
        { key: 'status', label: 'Status', card: 'hidden' },
        { key: 'lastError', label: 'Error', detail: true },
      ]),
    ).toEqual(['status', 'lastError'])
  })
})

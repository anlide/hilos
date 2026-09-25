import { describe, expect, it } from 'vitest'

import {
  createHilosMaintenanceCircleTable,
  resolveHilosMaintenanceCircleRow,
} from '../../../src/admin/maintenance/hilosMaintenance.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** Build a circle row whose inline `verifierCircle` slot carries the given fields. */
function circleRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { verifierCircle: slot } }
}

describe('resolveHilosMaintenanceCircleRow', () => {
  it('takes the membership id off the row key and the fields out of the slot', () => {
    const row = resolveHilosMaintenanceCircleRow(
      circleRow('7', {
        identityType: 'password',
        identifier: 'ann@example.test',
        online: true,
      }),
    )

    expect(row).toEqual({
      memberId: 7,
      identityType: 'password',
      identifier: 'ann@example.test',
      online: true,
    })
  })

  it('reads a row with no slot as nobody signed in', () => {
    const row = resolveHilosMaintenanceCircleRow(circleRow('12', undefined))

    expect(row.memberId).toBe(12)
    expect(row.identifier).toBe('')
    expect(row.online).toBe(false)
  })
})

describe('createHilosMaintenanceCircleTable', () => {
  it('holds its window on the maintenance page under the circle table key', () => {
    const registered: string[] = []
    const sent: Array<{ page: string; tableKey: string }> = []
    const connection = {
      on: () => () => {},
      registerTableWindow: (tableKey: string) => registered.push(tableKey),
      unregisterTableWindow: () => {},
      sendTableViewport: (page: string, tableKey: string) =>
        sent.push({ page, tableKey }) > 0,
    } as unknown as HilosConnection
    const circle = createHilosMaintenanceCircleTable({
      connection,
      scopes: new ScopeManager(),
    })

    circle.start()
    // The first window rides the page's own answer; a request for it again goes to the
    // same address.
    circle.controller.refresh()

    expect(registered).toEqual(['hilosVerifierCircle'])
    expect(sent.at(-1)).toEqual({
      page: 'hilos_maintenance',
      tableKey: 'hilosVerifierCircle',
    })
    circle.dispose()
  })
})

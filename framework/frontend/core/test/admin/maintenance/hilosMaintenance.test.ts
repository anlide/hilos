import { describe, expect, it } from 'vitest'

import {
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  type HilosMaintenanceContext,
  resolveHilosMaintenanceCircleRow,
} from '../../../src/admin/maintenance/hilosMaintenance.js'
import { type ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
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
      actions: {} as unknown as ActionLifecycle,
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

describe('createHilosMaintenanceActions', () => {
  /** A context whose action lifecycle records what was dispatched over it. */
  function dispatchContext(
    sent: Array<{ action: string; payload: unknown }>,
  ): HilosMaintenanceContext {
    const actions = {
      dispatch(action: string, payload: unknown) {
        sent.push({ action, payload })

        return { done: Promise.resolve(), loading: null }
      },
    } as unknown as ActionLifecycle

    return { actions } as unknown as HilosMaintenanceContext
  }

  it('names a verifier by the address exactly as the operator typed it', () => {
    const sent: Array<{ action: string; payload: unknown }> = []

    createHilosMaintenanceActions(
      dispatchContext(sent),
    ).sendMaintenanceCircleAdd('+7 900 000-00-00')

    // The stored form is the server's answer: the client neither trims nor normalizes.
    expect(sent).toEqual([
      {
        action: 'maintenance_circle_add',
        payload: { identifier: '+7 900 000-00-00' },
      },
    ])
  })
})

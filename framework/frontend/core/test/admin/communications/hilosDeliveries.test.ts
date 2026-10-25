import { describe, expect, it } from 'vitest'

import {
  createHilosDeliveriesActions,
  createHilosDeliveriesTable,
  isDeliveryRetryable,
  resolveHilosDeliveryRow,
  type HilosDeliveriesContext,
} from '../../../src/admin/communications/hilosDeliveries.js'
import {
  type ActionHandle,
  ActionLifecycle,
} from '../../../src/connection/actionLifecycle.js'
import { HilosConnection } from '../../../src/connection/HilosConnection.js'
import { formatMoment } from '../../../src/format/moment.js'
import { type ProjectSignal } from '../../../src/protocol/parseSignal.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'
import { bindTimeZoneState } from '../../../src/timeZone/timeZoneState.js'

/** Build a delivery-journal row whose inline `delivery` slot carries the given fields. */
function deliveryRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { delivery: slot } }
}

describe('resolveHilosDeliveryRow', () => {
  it('maps every slot field onto the view-model, formatting epoch ms as date and time strings in UTC and Pacific/Honolulu', () => {
    const listeners = new Set<(signal: ProjectSignal) => void>()
    const connection = {
      projectTimeZone: 'UTC',
      on(event: string, listener: (signal: ProjectSignal) => void) {
        if (event === 'projectSignal') listeners.add(listener)
        return () => {
          listeners.delete(listener)
        }
      },
    } as unknown as HilosConnection
    const releaseTime = bindTimeZoneState(connection)
    const choose = (timeZone: string | null): void => {
      for (const listener of listeners) {
        listener({
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 99, timeZone } } },
        } as ProjectSignal)
      }
    }

    const createdAtMs = 1785232800000
    const deliveredAtMs = 1785232803000

    const row = resolveHilosDeliveryRow(
      deliveryRow('42', {
        createdAt: createdAtMs,
        channel: 'mail',
        status: 'sent',
        attempts: 1,
        deliveredAt: deliveredAtMs,
        lastError: '',
        userId: 7,
        userLabel: 'Ada',
        notificationType: 'welcome',
        notificationTitle: 'Welcome aboard',
      }),
    )

    expect(row).toEqual({
      rowKey: '42',
      createdAt: formatMoment(createdAtMs, { kind: 'dateTime' }),
      channel: 'mail',
      status: 'sent',
      attempts: 1,
      deliveredAt: formatMoment(deliveredAtMs, { kind: 'dateTime' }),
      lastError: '',
      userId: 7,
      userLabel: 'Ada',
      notificationType: 'welcome',
      notificationTitle: 'Welcome aboard',
    })

    // Now format in Pacific/Honolulu
    choose('Pacific/Honolulu')

    const honoluluRow = resolveHilosDeliveryRow(
      deliveryRow('42', {
        createdAt: createdAtMs,
        channel: 'mail',
        status: 'sent',
        attempts: 1,
        deliveredAt: deliveredAtMs,
        lastError: '',
        userId: 7,
        userLabel: 'Ada',
        notificationType: 'welcome',
        notificationTitle: 'Welcome aboard',
      }),
    )

    expect(honoluluRow.createdAt).toBe(
      formatMoment(createdAtMs, { kind: 'dateTime' }),
    )
    expect(honoluluRow.deliveredAt).toBe(
      formatMoment(deliveredAtMs, { kind: 'dateTime' }),
    )
    expect(honoluluRow.createdAt).not.toBe(row.createdAt)

    releaseTime()
  })

  it('formats valid epoch 0 and gives protective empty string for null, missing or legacy strings', () => {
    const zeroRow = resolveHilosDeliveryRow(
      deliveryRow('1', {
        createdAt: 0,
        deliveredAt: 0,
      }),
    )
    expect(zeroRow.createdAt).toBe(formatMoment(0, { kind: 'dateTime' }))
    expect(zeroRow.deliveredAt).toBe(formatMoment(0, { kind: 'dateTime' }))
    expect(zeroRow.createdAt).not.toBe('')
    expect(zeroRow.deliveredAt).not.toBe('')

    // Legacy ISO string gives protective absence (''), not an invented epoch
    const legacyRow = resolveHilosDeliveryRow(
      deliveryRow('2', {
        createdAt: '2026-07-28T10:00:00+00:00',
        deliveredAt: '2026-07-28T10:00:03+00:00',
      }),
    )
    expect(legacyRow.createdAt).toBe('')
    expect(legacyRow.deliveredAt).toBe('')

    // Null deliveredAt
    const nullRow = resolveHilosDeliveryRow(
      deliveryRow('3', {
        createdAt: 1785232800000,
        deliveredAt: null,
      }),
    )
    expect(nullRow.deliveredAt).toBe('')
  })

  it('keeps a null recipient null rather than coercing it to zero', () => {
    const row = resolveHilosDeliveryRow(
      deliveryRow('9', { channel: 'mail', status: 'failed', userId: null }),
    )

    expect(row.userId).toBeNull()
  })

  it('falls back to the row key and safe defaults when the slot is absent', () => {
    const row = resolveHilosDeliveryRow(deliveryRow('5', undefined))

    expect(row.rowKey).toBe('5')
    expect(row.createdAt).toBe('')
    expect(row.channel).toBe('')
    expect(row.status).toBe('')
    expect(row.attempts).toBe(0)
    expect(row.deliveredAt).toBe('')
    expect(row.lastError).toBe('')
    expect(row.userId).toBeNull()
  })

  it('reads the recipient, the title and the error sent hidden as the one hidden value (HIL-1260)', () => {
    const row = resolveHilosDeliveryRow(
      deliveryRow('8', {
        status: 'failed',
        lastError: { _hidden: true },
        userId: 7,
        userLabel: { _hidden: true },
        notificationTitle: { _hidden: true },
      }),
    )

    expect(row.lastError).toBe(HIDDEN_VALUE)
    expect(row.userLabel).toBe(HIDDEN_VALUE)
    expect(row.notificationTitle).toBe(HIDDEN_VALUE)
    expect(row.userId).toBe(7)
  })
})

describe('isDeliveryRetryable', () => {
  it('is true only for a failed delivery', () => {
    expect(
      isDeliveryRetryable(
        resolveHilosDeliveryRow(deliveryRow('1', { status: 'failed' })),
      ),
    ).toBe(true)
    expect(
      isDeliveryRetryable(
        resolveHilosDeliveryRow(deliveryRow('2', { status: 'pending' })),
      ),
    ).toBe(false)
    expect(
      isDeliveryRetryable(
        resolveHilosDeliveryRow(deliveryRow('3', { status: 'sent' })),
      ),
    ).toBe(false)
  })
})

describe('createHilosDeliveriesActions', () => {
  it('dispatches retry with the delivery id', () => {
    const calls: Array<{ action: string; payload: Record<string, unknown> }> =
      []
    const context = {
      connection: {},
      scopes: {},
      actions: {
        dispatch(
          action: string,
          payload: Record<string, unknown>,
        ): ActionHandle {
          calls.push({ action, payload })

          return {} as ActionHandle
        },
      },
    } as unknown as HilosDeliveriesContext

    createHilosDeliveriesActions(context).sendDeliveryRetry(42)

    expect(calls).toEqual([
      { action: 'communications_delivery_retry', payload: { deliveryId: 42 } },
    ])
  })
})

describe('deliveries table reactive timezone recomputation', () => {
  it('recomputes controller.rows after timezone change without a new window', () => {
    const listeners = new Set<(signal: ProjectSignal) => void>()
    const connection = {
      projectTimeZone: 'UTC',
      on(event: string, listener: (signal: ProjectSignal) => void) {
        if (event === 'projectSignal') listeners.add(listener)
        return () => {
          listeners.delete(listener)
        }
      },
      sendTableViewport: () => true,
      sendTableRendered: () => true,
      registerTableWindow: () => () => {},
      unregisterTableWindow: () => {},
    } as unknown as HilosConnection
    const releaseTime = bindTimeZoneState(connection)
    const choose = (timeZone: string | null): void => {
      for (const listener of listeners) {
        listener({
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 99, timeZone } } },
        } as ProjectSignal)
      }
    }

    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.COMMUNICATIONS_DELIVERIES)
    const actions = new ActionLifecycle({
      sendAction: () => true,
      on: () => () => {},
    } as unknown as ConstructorParameters<typeof ActionLifecycle>[0])

    const context: HilosDeliveriesContext = {
      connection,
      scopes,
      actions,
    }

    const table = createHilosDeliveriesTable(context)
    table.start()

    const createdAtMs = 1785232800000
    table.controller.ingestSubscriptionWindow(
      [
        deliveryRow('42', {
          createdAt: createdAtMs,
          channel: 'mail',
          status: 'sent',
          attempts: 1,
          deliveredAt: null,
        }),
      ],
      1,
      true,
      null,
      null,
      25,
      undefined,
      [],
    )

    const rowsBefore = table.controller.rows.get()
    expect(rowsBefore).toHaveLength(1)
    const utcDisplay = rowsBefore[0]?.row?.createdAt
    expect(typeof utcDisplay).toBe('string')
    expect(utcDisplay).toBe(formatMoment(createdAtMs, { kind: 'dateTime' }))

    // Switch zone to Pacific/Honolulu
    choose('Pacific/Honolulu')

    const rowsAfter = table.controller.rows.get()
    expect(rowsAfter).toHaveLength(1)
    const honoluluDisplay = rowsAfter[0]?.row?.createdAt
    expect(typeof honoluluDisplay).toBe('string')
    expect(honoluluDisplay).toBe(
      formatMoment(createdAtMs, { kind: 'dateTime' }),
    )
    expect(honoluluDisplay).not.toBe(utcDisplay)

    table.dispose()
    releaseTime()
  })
})

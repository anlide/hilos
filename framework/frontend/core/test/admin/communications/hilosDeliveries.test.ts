import { describe, expect, it } from 'vitest'

import {
  createHilosDeliveriesActions,
  isDeliveryRetryable,
  resolveHilosDeliveryRow,
  type HilosDeliveriesContext,
} from '../../../src/admin/communications/hilosDeliveries.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** Build a delivery-journal row whose inline `delivery` slot carries the given fields. */
function deliveryRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { delivery: slot } }
}

describe('resolveHilosDeliveryRow', () => {
  it('maps every slot field onto the view-model, keeping the row key as identity', () => {
    const row = resolveHilosDeliveryRow(
      deliveryRow('42', {
        createdAt: '2026-07-28T10:00:00+00:00',
        channel: 'mail',
        status: 'sent',
        attempts: 1,
        deliveredAt: '2026-07-28T10:00:03+00:00',
        lastError: '',
        userId: 7,
        userLabel: 'Ada',
        notificationType: 'welcome',
        notificationTitle: 'Welcome aboard',
      }),
    )

    expect(row).toEqual({
      rowKey: '42',
      createdAt: '2026-07-28T10:00:00+00:00',
      channel: 'mail',
      status: 'sent',
      attempts: 1,
      deliveredAt: '2026-07-28T10:00:03+00:00',
      lastError: '',
      userId: 7,
      userLabel: 'Ada',
      notificationType: 'welcome',
      notificationTitle: 'Welcome aboard',
    })
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
    expect(row.channel).toBe('')
    expect(row.status).toBe('')
    expect(row.attempts).toBe(0)
    expect(row.deliveredAt).toBe('')
    expect(row.lastError).toBe('')
    expect(row.userId).toBeNull()
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

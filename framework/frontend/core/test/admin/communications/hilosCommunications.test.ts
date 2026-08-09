import { describe, expect, it } from 'vitest'

import {
  createHilosCommunicationsActions,
  resolveHilosChannelFieldRow,
  resolveHilosChannelRow,
  type HilosCommunicationsContext,
} from '../../../src/admin/communications/hilosCommunications.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

/** Build a channels-hub row whose inline `channel` slot carries the given fields. */
function channelRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { channel: slot } }
}

/** Build a channel-fields row whose inline `field` slot carries the given fields. */
function fieldRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { field: slot } }
}

describe('resolveHilosChannelRow', () => {
  it('maps every slot field onto the view-model', () => {
    const row = resolveHilosChannelRow(
      channelRow('email', {
        channel: 'email',
        label: 'Email',
        enabled: true,
        configured: false,
        driver: 'smtp',
        missingFields: 2,
      }),
    )

    expect(row).toEqual({
      channel: 'email',
      label: 'Email',
      enabled: true,
      configured: false,
      driver: 'smtp',
      missingFields: 2,
    })
  })

  it('falls back to the row key and safe defaults when the slot is absent', () => {
    const row = resolveHilosChannelRow(channelRow('sms', undefined))

    expect(row.channel).toBe('sms')
    // An unlabelled channel shows its key, not a blank cell.
    expect(row.label).toBe('sms')
    expect(row.enabled).toBe(false)
    expect(row.configured).toBe(false)
    // No driver is null — "none configured", not "configured with nothing".
    expect(row.driver).toBeNull()
    expect(row.missingFields).toBe(0)
  })
})

describe('resolveHilosChannelFieldRow', () => {
  it('maps a typed non-secret field, keeping its scalar value type', () => {
    const row = resolveHilosChannelFieldRow(
      fieldRow('notifications.channel.email.smtp_port', {
        channel: 'email',
        field: 'smtp_port',
        label: 'SMTP port',
        type: 'integer',
        value: 587,
        valueSource: 'env',
        secret: false,
        editable: true,
      }),
    )

    expect(row).toEqual({
      key: 'notifications.channel.email.smtp_port',
      channel: 'email',
      field: 'smtp_port',
      label: 'SMTP port',
      type: 'integer',
      value: 587,
      valueSource: 'env',
      secret: false,
      editable: true,
    })
  })

  it('never surfaces a secret value and marks it non-editable', () => {
    const row = resolveHilosChannelFieldRow(
      fieldRow('notifications.channel.email.smtp_password', {
        channel: 'email',
        field: 'smtp_password',
        label: 'SMTP password',
        type: 'string',
        // A secret's value is null on the wire; even a leaked value would be dropped.
        value: null,
        valueSource: 'env',
        secret: true,
        editable: false,
      }),
    )

    expect(row.value).toBeNull()
    expect(row.secret).toBe(true)
    expect(row.editable).toBe(false)
  })

  it('narrows an unknown value source to default', () => {
    const row = resolveHilosChannelFieldRow(
      fieldRow('k', { channel: 'email', valueSource: 'not-a-source' }),
    )

    expect(row.valueSource).toBe('default')
  })
})

describe('createHilosCommunicationsActions', () => {
  /** A context whose action lifecycle records every dispatch. */
  function recordingContext(): {
    context: HilosCommunicationsContext
    calls: Array<{ action: string; payload: Record<string, unknown> }>
  } {
    const calls: Array<{
      action: string
      payload: Record<string, unknown>
    }> = []
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
    } as unknown as HilosCommunicationsContext

    return { context, calls }
  }

  it('dispatches set with the channel, field, and value', () => {
    const { context, calls } = recordingContext()
    createHilosCommunicationsActions(context).sendChannelSet(
      'email',
      'enabled',
      true,
    )

    expect(calls).toEqual([
      {
        action: 'communications_channel_set',
        payload: { channel: 'email', field: 'enabled', value: true },
      },
    ])
  })

  it('dispatches reset with the channel and field only', () => {
    const { context, calls } = recordingContext()
    createHilosCommunicationsActions(context).sendChannelReset(
      'email',
      'smtp_host',
    )

    expect(calls).toEqual([
      {
        action: 'communications_channel_reset',
        payload: { channel: 'email', field: 'smtp_host' },
      },
    ])
  })

  it('dispatches test with the channel only', () => {
    const { context, calls } = recordingContext()
    createHilosCommunicationsActions(context).sendChannelTest('email')

    expect(calls).toEqual([
      { action: 'communications_channel_test', payload: { channel: 'email' } },
    ])
  })
})

import { describe, expect, it, vi } from 'vitest'

import {
  createHilosChannelFields,
  createHilosCommunicationsActions,
  resolveHilosChannelFieldRow,
  resolveHilosChannelRow,
  type HilosCommunicationsContext,
} from '../../../src/admin/communications/hilosCommunications.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { Emitter } from '../../../src/connection/events.js'
import {
  type HilosConnection,
  type HilosConnectionEventMap,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
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

  it('preserves a hidden value mark on the enabled field', () => {
    const row = resolveHilosChannelRow(
      channelRow('email', {
        channel: 'email',
        label: 'Email',
        enabled: { _hidden: true },
        configured: false,
        driver: 'smtp',
        missingFields: 2,
      }),
    )

    expect(row.enabled).toBe(HIDDEN_VALUE)
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
        defaultValue: 587,
        defaultValueSource: 'env',
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
      defaultValue: 587,
      defaultValueSource: 'env',
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
        defaultValue: null,
        defaultValueSource: 'env',
        secret: true,
        editable: false,
      }),
    )

    expect(row.value).toBeNull()
    expect(row.defaultValue).toBeNull()
    expect(row.secret).toBe(true)
    expect(row.editable).toBe(false)
  })

  it('preserves a hidden value mark on the value field', () => {
    const row = resolveHilosChannelFieldRow(
      fieldRow('notifications.channel.email.smtp_port', {
        channel: 'email',
        field: 'smtp_port',
        label: 'SMTP port',
        type: 'integer',
        value: { _hidden: true },
        valueSource: 'env',
        defaultValue: 587,
        defaultValueSource: 'env',
        secret: false,
        editable: true,
      }),
    )

    expect(row.value).toBe(HIDDEN_VALUE)
  })

  it.each([false, true, 0, 0.5, 587, '', 'env.example', null])(
    'preserves the fallback scalar or null %j',
    (defaultValue) => {
      const row = resolveHilosChannelFieldRow(
        fieldRow('k', { defaultValue, defaultValueSource: 'env' }),
      )
      expect(row.defaultValue).toBe(defaultValue)
      expect(row.defaultValueSource).toBe('env')
    },
  )

  it('keeps a hidden fallback as the canonical marker', () => {
    const row = resolveHilosChannelFieldRow(
      fieldRow('k', {
        defaultValue: { _hidden: true },
        defaultValueSource: 'default',
      }),
    )
    expect(row.defaultValue).toBe(HIDDEN_VALUE)
    expect(row.defaultValueSource).toBe('default')
  })

  it.each(['settings', 'invalid', undefined])(
    'rejects %j as a fallback source',
    (defaultValueSource) => {
      const row = resolveHilosChannelFieldRow(
        fieldRow('k', { defaultValueSource }),
      )
      expect(row.defaultValueSource).toBe('default')
    },
  )

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

describe('createHilosChannelFields', () => {
  it.each([
    undefined,
    'hilos_communications_channel_configuration',
    'project_channel_configuration',
  ])(
    'uses page %s for viewport, rendered fields, focus and incoming windows',
    (page) => {
      const expectedPage = page ?? 'hilos_communications_channel'
      const tableKey = 'hilosCommunicationsChannelFields'
      const emitter = new Emitter<HilosConnectionEventMap>()
      const sendTableViewport = vi.fn(() => true)
      const sendTableRendered = vi.fn(() => true)
      const sendTableRowFocus = vi.fn(() => true)
      const registerTableWindow = vi.fn()
      const unregisterTableWindow = vi.fn()
      const scopes = new ScopeManager()
      const context = {
        connection: {
          on: emitter.on.bind(emitter),
          sendTableViewport,
          sendTableRendered,
          sendTableRowFocus,
          registerTableWindow,
          unregisterTableWindow,
        },
        scopes,
      } as unknown as HilosCommunicationsContext
      // With no route preset yet, the first subscription window can be drawn immediately.
      const channel = createSignal('')
      const fields =
        page === undefined
          ? createHilosChannelFields(context, channel)
          : createHilosChannelFields(context, channel, page)
      const rowKey = 'notifications.channel.email.smtp_port'
      const row = fieldRow(rowKey, {
        channel: 'email',
        field: 'smtp_port',
        type: 'integer',
        value: 587,
      })
      const emitWindow = (addressedPage: string): void => {
        const data = {
          page: addressedPage,
          payload: {
            windows: {
              [tableKey]: {
                rows: [row],
                sort: [],
                totalCount: 1,
                totalExact: true,
                firstAnchor: { key: rowKey },
                lastAnchor: { key: rowKey },
                limit: 10,
              },
            },
          },
        }
        emitter.emit('projectSignal', {
          kind: 'project',
          type: 'page_response',
          data,
          envelope: { type: 'page_response', data },
        })
      }

      fields.start()
      expect(registerTableWindow).toHaveBeenCalledWith(
        tableKey,
        fields.controller,
      )
      scopes.openPage('other_page')
      emitWindow(expectedPage)
      expect(fields.controller.loaded.get()).toBe(false)
      scopes.openPage(expectedPage)
      emitWindow('other_page')
      expect(fields.controller.loaded.get()).toBe(false)

      emitWindow(expectedPage)
      expect(fields.controller.rows.get().map((entry) => entry.rowKey)).toEqual(
        [rowKey],
      )
      expect(sendTableRendered).toHaveBeenCalledOnce()
      expect(sendTableRendered).toHaveBeenCalledWith(
        expectedPage,
        tableKey,
        expect.arrayContaining([
          'field',
          'value',
          'valueSource',
          'defaultValue',
        ]),
      )
      expect(fields.controller.focusRow(rowKey)?.key).toBe(rowKey)
      fields.controller.releaseFocus()
      expect(sendTableRowFocus.mock.calls).toEqual([
        [expectedPage, tableKey, rowKey],
        [expectedPage, tableKey, ''],
      ])

      const rows = fields.controller.rows.get()
      const data = {
        page: expectedPage,
        payload: { data: { channelSummary: { channel: 'email' } } },
      }
      emitter.emit('projectSignal', {
        kind: 'project',
        type: 'page_response',
        data,
        envelope: { type: 'page_response', data },
      })
      expect(fields.controller.rows.get()).toBe(rows)
      expect(fields.controller.loaded.get()).toBe(true)
      expect(sendTableViewport).not.toHaveBeenCalled()
      expect(sendTableRendered).toHaveBeenCalledOnce()

      channel.set('sms')
      expect(sendTableViewport).toHaveBeenCalledOnce()
      expect(sendTableViewport).toHaveBeenCalledWith(
        expectedPage,
        tableKey,
        expect.objectContaining({ filter: { channel: 'sms' } }),
      )
      fields.dispose()
      expect(unregisterTableWindow).toHaveBeenCalledWith(
        tableKey,
        fields.controller,
      )
      channel.set('push')
      expect(sendTableViewport).toHaveBeenCalledOnce()
    },
  )

  it('declares a bare frame: no title under the page heading, no search over a handful of fields', () => {
    const fields = createHilosChannelFields(
      {
        connection: {},
        scopes: new ScopeManager(),
      } as unknown as HilosCommunicationsContext,
      createSignal('email'),
    )
    const declaration = fields.controller.frame.declaration

    expect(
      fields.controller.frame.columns
        .get()
        .find((column) => column.key === 'actions')?.reads,
    ).toEqual([
      'editable',
      'valueSource',
      'channel',
      'field',
      'type',
      'label',
      'value',
      'defaultValue',
      'defaultValueSource',
    ])
    expect(declaration?.title).toBeUndefined()
    expect(declaration?.search).toBeUndefined()
    expect(declaration?.empty?.title).toBe(
      'No configurable fields for this channel.',
    )
  })

  it('narrows the table to the route channel on the server and follows the route', () => {
    const sent: TableViewportDescriptor[] = []
    const connection = {
      on: () => () => {},
      registerTableWindow: () => {},
      unregisterTableWindow: () => {},
      sendTableViewport: (
        _page: string,
        _tableKey: string,
        descriptor: TableViewportDescriptor,
      ) => sent.push(descriptor) > 0,
    } as unknown as HilosConnection
    const channel = createSignal('email')
    const fields = createHilosChannelFields(
      {
        connection,
        scopes: new ScopeManager(),
      } as unknown as HilosCommunicationsContext,
      channel,
    )

    fields.start()
    channel.set('sms')

    // No filter is applied on the client any more: the next channel is a new window.
    expect(sent.at(-1)?.filter).toEqual({ channel: 'sms' })
    fields.dispose()
  })
})

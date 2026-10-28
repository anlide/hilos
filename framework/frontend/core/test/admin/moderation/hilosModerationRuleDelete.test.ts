import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosModerationRuleDelete,
  createHilosModerationRules,
  HILOS_MODERATION_RULE_DELETE_ACTION,
  HILOS_MODERATION_RULE_DELETE_COPY,
  type HilosModerationKindRow,
  type HilosModerationRulesContext,
} from '../../../src/admin/moderation/index.js'
import { type HilosActionRun } from '../../../src/conflict/rowEditSession.js'
import { ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import {
  HilosConnection,
  type WebSocketLike,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { computedSignal, createSignal } from '../../../src/state/signal.js'

const cleanups: Array<() => void> = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0)) cleanup()
})

function kindRow(kind = 'first', title = 'First kind'): HilosModerationKindRow {
  return {
    kind,
    title,
    owner: 'framework',
    input: 'text',
    model: 'model',
    ownRuleCount: 0,
    profile: 'text',
    reasons: [],
    defaultRules: ['Built-in rule'],
  }
}

function wireRule(id = 1, kind = 'first', rule = '  Raw text\n') {
  return { rowKey: String(id), slots: { rule: { id, kind, rule } } }
}

/** Only the socket and page projections are scripted; actions await real parsed replies. */
function setup() {
  const listeners = new Map<string, (event: { data?: unknown }) => void>()
  const sent: Array<Record<string, unknown>> = []
  const socket: WebSocketLike = {
    send: (text) => sent.push(JSON.parse(String(text))),
    close: () => {},
    addEventListener: (name, listener) => listeners.set(name, listener),
  }
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: () => socket,
  })
  const actions = new ActionLifecycle(connection)
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MODERATION_KIND_RULES)
  const source = createSignal<HilosModerationKindRow | null>(kindRow())
  const context: HilosModerationRulesContext = { connection, scopes, actions }
  const rules = createHilosModerationRules(
    context,
    computedSignal(() => source.get()?.kind ?? ''),
  )
  const current = rules.current
  const remove = createHilosModerationRuleDelete(context, source, rules)
  function window(rows = [wireRule()]) {
    rules.controller.ingestWindow(rows, rows.length, true, null, null, 25, 0)
  }
  const failures: unknown[] = []
  const run: HilosActionRun = vi.fn(async (handle) => {
    try {
      await handle.done
      return true
    } catch (error) {
      failures.push(error)
      return false
    }
  })
  connection.connect()
  listeners.get('open')?.({})
  rules.start()
  window()
  remove.start()
  cleanups.push(() => {
    remove.dispose()
    rules.dispose()
    connection.close()
  })
  return {
    remove,
    rules,
    window,
    actions,
    scopes,
    source,
    current,
    run,
    failures,
    sent,
    reply(requestId = String(sent.at(-1)?.requestId), success = true) {
      listeners.get('message')?.({
        data: JSON.stringify({
          type: success ? 'action_success' : 'action_error',
          requestId,
          outcome: success ? 'success' : 'fail',
          data: {
            action: 'hilos_moderation_rule_delete',
            ...(success
              ? { message: 'Rule removed from "First kind".' }
              : { reason: 'This rule could not be removed.' }),
          },
        }),
      })
    },
  }
}

describe('createHilosModerationRuleDelete', () => {
  it('shares the approved action and confirmation words', () => {
    expect(HILOS_MODERATION_RULE_DELETE_ACTION).toBe(
      'hilos_moderation_rule_delete',
    )
    expect(HILOS_MODERATION_RULE_DELETE_COPY).toMatchObject({
      open: 'Delete rule',
      title: 'Delete rule',
      confirm: 'Delete',
      cancel: 'Cancel',
      refusalTitle: "Couldn't delete the rule",
      deleted: 'The rule no longer exists.',
      changed: 'The rule changed. Close this window and confirm again.',
    })
    expect(HILOS_MODERATION_RULE_DELETE_COPY.body('First kind')).toBe(
      'This permanently removes the rule from First kind.',
    )
  })

  it('captures fresh raw text, immutable title and numeric identity through focus', () => {
    const h = setup()
    const c = h.rules.controller
    c.ingestDelta({
      kind: 'row_moved',
      rowKey: '1',
      position: 0,
      row: wireRule(1, 'first', '  Fresh\n\n'),
    })
    expect(c.pendingCount.get()).toBe(1)
    h.remove.open('1')
    expect(c.pendingCount.get()).toBe(0)
    expect(h.remove.target.get()).toEqual({
      id: 1,
      kind: 'first',
      title: 'First kind',
      expectedRule: '  Fresh\n\n',
    })
    h.source.set(kindRow('first', 'Renamed kind'))
    expect(h.remove.target.get()?.title).toBe('First kind')
    expect(h.remove.canSubmit.get()).toBe(true)
    expect(h.sent.at(-1)).toEqual({
      type: 'table_row_focus',
      page: HilosPages.MODERATION_KIND_RULES,
      tableKey: 'hilosModerationRules',
      rowKey: '1',
    })
    h.remove.close()
    expect(c.focusedRow.get()).toBeUndefined()
    expect(h.sent.at(-1)).toMatchObject({ type: 'table_row_focus', rowKey: '' })
    expect(h.remove.target.get()).toBeNull()
    expect(h.remove.notice.get()).toBeNull()
  })

  it('does not open on absent or pending-deleted rows or stale provenance', async () => {
    const h = setup()
    h.remove.open('missing')
    expect(h.remove.openState.get()).toBe(false)
    h.rules.controller.ingestDelta({
      kind: 'row_removed',
      rowKey: '1',
      reason: 'deleted',
    })
    h.remove.open('1')
    expect(h.remove.openState.get()).toBe(false)
    h.source.set(kindRow('second'))
    h.remove.open('1')
    expect(h.remove.openState.get()).toBe(false)
    h.scopes.openPage(HilosPages.MODERATION_KIND)
    h.remove.open('1')
    await h.remove.submit(h.run)
    expect(h.run).not.toHaveBeenCalled()
  })

  it.each([0, -1, 1.5])('does not capture an invalid rule id %s', (id) => {
    const h = setup()
    h.window([wireRule(id)])
    h.remove.open(String(id))
    expect(h.remove.openState.get()).toBe(false)
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
  })

  it('holds a conflict until a fresh opening, including raw whitespace changes', async () => {
    const h = setup()
    h.remove.open('1')
    const captured = h.remove.target.get()
    h.rules.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: '1',
      row: wireRule(1, 'first', 'Raw text'),
    })
    expect(h.remove.notice.get()).toEqual({
      kind: 'conflict',
      text: 'The rule changed. Close this window and confirm again.',
    })
    expect(h.remove.target.get()).toBe(captured)
    await h.remove.submit(h.run)
    expect(h.run).not.toHaveBeenCalled()
    h.rules.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: '1',
      row: wireRule(),
    })
    expect(h.remove.canSubmit.get()).toBe(false)
    h.remove.close()
    h.remove.open('1')
    expect(h.remove.notice.get()).toBeNull()
    expect(h.remove.canSubmit.get()).toBe(true)
  })

  it.each(['deleted', 'other focus', 'wrong kind', 'wrong id'])(
    'keeps the captured target readable and unsendable after %s',
    async (change) => {
      const h = setup()
      h.window([wireRule(), wireRule(2)])
      h.remove.open('1')
      const captured = h.remove.target.get()
      const c = h.rules.controller
      if (change === 'deleted')
        c.ingestDelta({ kind: 'row_removed', rowKey: '1', reason: 'deleted' })
      if (change === 'other focus') c.focusRow('2')
      if (change === 'wrong kind')
        c.ingestDelta({
          kind: 'row_updated',
          rowKey: '1',
          row: wireRule(1, 'second'),
        })
      if (change === 'wrong id')
        c.ingestDelta({
          kind: 'row_updated',
          rowKey: '1',
          row: { ...wireRule(2), rowKey: '1' },
        })
      expect(h.remove.notice.get()).toEqual({
        kind: 'deleted',
        text: 'The rule no longer exists.',
      })
      expect(h.remove.openState.get()).toBe(true)
      expect(h.remove.target.get()).toBe(captured)
      expect(h.remove.submitAvailable.get()).toBe(false)
      await h.remove.submit(h.run)
      expect(h.run).not.toHaveBeenCalled()
    },
  )

  it('reads bodies outside the window while preserving the table Apply gate', () => {
    const h = setup()
    h.remove.open('1')
    const c = h.rules.controller
    c.ingestDelta({
      kind: 'row_removed',
      rowKey: '1',
      reason: 'left_set',
      row: wireRule(),
    })
    expect(c.pendingCount.get()).toBe(1)
    expect(h.remove.canSubmit.get()).toBe(true)
    c.apply()
    expect(c.rows.get()[0].placeholder).toBe(true)
    expect(h.remove.canSubmit.get()).toBe(true)
    c.ingestDelta({
      kind: 'row_removed',
      rowKey: '1',
      reason: 'moved_out',
      row: wireRule(1, 'first', 'Changed outside'),
    })
    expect(h.remove.notice.get()?.kind).toBe('conflict')
    expect(h.remove.target.get()?.expectedRule).toBe('  Raw text\n')
  })

  it('locks synchronously, sends the exact snapshot and closes only on correlated success', async () => {
    const h = setup()
    h.remove.open('1')
    const pending = h.remove.submit(h.run)
    expect(h.remove.saving.get()).toBe(true)
    expect(h.remove.submitAvailable.get()).toBe(true)
    expect(h.remove.canSubmit.get()).toBe(false)
    h.remove.close()
    h.remove.open('missing')
    await h.remove.submit(h.run)
    const sent = h.sent.filter((f) => f.type === 'action')
    expect(sent).toEqual([
      {
        type: 'action',
        action: 'hilos_moderation_rule_delete',
        data: { id: 1, kind: 'first', expectedRule: '  Raw text\n' },
        requestId: expect.any(String),
      },
    ])
    expect(h.rules.controller.rows.get()[0].row?.id).toBe(1)
    h.reply('unrelated')
    await Promise.resolve()
    expect(h.remove.openState.get()).toBe(true)
    expect(h.remove.saving.get()).toBe(true)
    h.reply()
    await pending
    expect(h.remove.openState.get()).toBe(false)
    expect(h.remove.saving.get()).toBe(false)
    expect(h.remove.target.get()).toBeNull()
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
    expect(h.rules.controller.rows.get()[0].row?.id).toBe(1)
  })

  it('retains a refusal and retries only on an explicit submit', async () => {
    const h = setup()
    h.remove.open('1')
    const captured = h.remove.target.get()
    const pending = h.remove.submit(h.run)
    h.reply(undefined, false)
    await pending
    expect(h.failures).toMatchObject([
      { message: 'This rule could not be removed.' },
    ])
    expect(h.remove.openState.get()).toBe(true)
    expect(h.remove.target.get()).toBe(captured)
    expect(h.remove.saving.get()).toBe(false)
    expect(h.remove.canSubmit.get()).toBe(true)
    expect(h.sent.filter((f) => f.type === 'action')).toHaveLength(1)
    const retry = h.remove.submit(h.run)
    h.reply()
    await retry
    expect(h.remove.openState.get()).toBe(false)
  })

  it('blocks a no-longer-current window without replacing its confirmation', async () => {
    const h = setup()
    h.remove.open('1')
    h.rules.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: '1',
      row: wireRule(1, 'second'),
    })
    expect(h.current.get()).toBe(false)
    await h.remove.submit(h.run)
    expect(h.run).not.toHaveBeenCalled()
    expect(h.remove.openState.get()).toBe(true)
  })

  it.each(['kind', 'scope', 'departure', 'missing kind', 'dispose'])(
    'releases focus and invalidates an in-flight attempt on %s',
    async (change) => {
      const h = setup()
      h.remove.open('1')
      const pending = h.remove.submit(h.run)
      const oldId = String(h.sent.at(-1)?.requestId)
      if (change === 'kind') h.source.set(kindRow('second'))
      if (change === 'scope')
        h.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
      if (change === 'departure') h.scopes.dropPage()
      if (change === 'missing kind') h.source.set(null)
      if (change === 'dispose') h.remove.dispose()
      expect(h.remove.openState.get()).toBe(false)
      expect(h.remove.saving.get()).toBe(false)
      expect(h.rules.controller.focusedRow.get()).toBeUndefined()
      h.reply(oldId)
      await pending
      expect(h.remove.openState.get()).toBe(false)
    },
  )

  it.each([true, false])(
    'ignores a late old result (%s) during a replacement save',
    async (success) => {
      const h = setup()
      h.remove.open('1')
      const old = h.remove.submit(h.run)
      const oldId = String(h.sent.at(-1)?.requestId)
      h.remove.dispose()
      h.remove.dispose()
      h.remove.start()
      h.remove.start()
      h.window([wireRule(2)])
      h.remove.open('2')
      const fresh = h.remove.submit(h.run)
      const freshId = String(h.sent.at(-1)?.requestId)
      h.reply(oldId, success)
      await old
      expect(h.remove.target.get()?.id).toBe(2)
      expect(h.remove.saving.get()).toBe(true)
      expect(h.rules.controller.focusedRow.get()?.id).toBe(2)
      h.reply(freshId)
      await fresh
      expect(h.remove.openState.get()).toBe(false)
    },
  )

  it('keeps start idempotent while open and clears state on close and dispose', () => {
    const h = setup()
    h.remove.open('1')
    const captured = h.remove.target.get()
    h.remove.start()
    h.remove.start()
    expect(h.remove.target.get()).toBe(captured)
    h.remove.close()
    h.remove.close()
    expect(h.remove.canSubmit.get()).toBe(false)
    h.remove.open('1')
    h.remove.dispose()
    h.remove.dispose()
    expect(h.remove.target.get()).toBeNull()
    h.remove.start()
    h.remove.open('1')
    h.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
    expect(h.remove.openState.get()).toBe(false)
  })

  it('releases saving and preserves the target if dispatch throws', async () => {
    const h = setup()
    h.remove.open('1')
    vi.spyOn(h.actions, 'dispatch').mockImplementation(() => {
      throw new Error('Dispatch failed')
    })
    await expect(h.remove.submit(h.run)).rejects.toThrow('Dispatch failed')
    expect(h.remove.saving.get()).toBe(false)
    expect(h.remove.openState.get()).toBe(true)
    expect(h.remove.canSubmit.get()).toBe(true)
  })
})

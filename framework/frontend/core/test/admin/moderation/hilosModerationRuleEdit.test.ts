import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosModerationRuleEdit,
  createHilosModerationRules,
  HILOS_MODERATION_RULE_EDIT_COPY,
  HILOS_MODERATION_RULE_UPDATE_ACTION,
  type HilosModerationKindRow,
  type HilosModerationRulesContext,
} from '../../../src/admin/moderation/index.js'
import {
  HILOS_ROW_EDIT_COPY,
  type HilosActionRun,
} from '../../../src/conflict/rowEditSession.js'
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

/** Real table focus, row session and action replies; only the socket and kind are scripted. */
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
  const kind = createSignal<HilosModerationKindRow | null>(kindRow())
  const context: HilosModerationRulesContext = { connection, scopes, actions }
  const rules = createHilosModerationRules(
    context,
    computedSignal(() => kind.get()?.kind ?? ''),
  )
  const edit = createHilosModerationRuleEdit(context, kind, rules)
  function window(rows = [wireRule()]) {
    rules.controller.ingestWindow(rows, rows.length, true, null, null, 25, 0)
  }
  function incoming(rule: string) {
    rules.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: '1',
      row: wireRule(1, 'first', rule),
    })
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
  edit.start()
  cleanups.push(() => {
    edit.dispose()
    rules.dispose()
    connection.close()
  })
  const writes = () => sent.filter((frame) => frame.type === 'action')
  return {
    edit,
    rules,
    window,
    incoming,
    actions,
    scopes,
    kind,
    run,
    failures,
    sent,
    writes,
    reply(requestId = String(writes().at(-1)?.requestId), success = true) {
      listeners.get('message')?.({
        data: JSON.stringify({
          type: success ? 'action_success' : 'action_error',
          requestId,
          outcome: success ? 'success' : 'fail',
          data: {
            action: 'hilos_moderation_rule_update',
            ...(success
              ? { message: 'Rule updated for "First kind".' }
              : { reason: 'The rule changed. Review it and try again.' }),
          },
        }),
      })
    },
  }
}

describe('createHilosModerationRuleEdit', () => {
  it('shares the approved action and edit words', () => {
    expect(HILOS_MODERATION_RULE_UPDATE_ACTION).toBe(
      'hilos_moderation_rule_update',
    )
    expect(HILOS_MODERATION_RULE_EDIT_COPY).toMatchObject({
      open: 'Edit rule',
      ruleLabel: 'Rule',
      cancel: 'Cancel',
      refusalTitle: "Couldn't save the rule",
    })
    expect(HILOS_MODERATION_RULE_EDIT_COPY.title('First kind')).toBe(
      'Edit rule · First kind',
    )
    expect(HILOS_MODERATION_RULE_EDIT_COPY.conflict('New rule')).toBe(
      'Rule changed elsewhere to "New rule".',
    )
  })

  it('opens the fresh focused id and raw form, keeping its target title fixed', async () => {
    const h = setup()
    h.rules.controller.ingestDelta({
      kind: 'row_moved',
      rowKey: '1',
      position: 0,
      row: wireRule(1, 'first', '  Fresh\n\n'),
    })
    expect(h.rules.controller.pendingCount.get()).toBe(1)
    expect(h.edit.open('1')).toBe(true)
    expect(h.rules.controller.pendingCount.get()).toBe(0)
    expect(h.edit.target.get()).toEqual({
      id: 1,
      kind: 'first',
      title: 'First kind',
    })
    expect(h.edit.form.get()).toEqual({ rule: '  Fresh\n\n' })
    expect(h.edit.dirty.get()).toBe(false)
    expect(h.edit.canSave.get()).toBe(false)
    expect(h.edit.saveLabel.get()).toBe(HILOS_ROW_EDIT_COPY.save)
    h.kind.set(kindRow('first', 'Renamed kind'))
    expect(h.edit.target.get()?.title).toBe('First kind')
    expect(h.sent.at(-1)).toEqual({
      type: 'table_row_focus',
      page: HilosPages.MODERATION_KIND_RULES,
      tableKey: 'hilosModerationRules',
      rowKey: '1',
    })
    await h.edit.save(h.run)
    expect(h.writes()).toEqual([])
    expect(h.edit.opened.get()).toBe(false)
    expect(h.edit.target.get()).toBeNull()
    expect(h.sent.at(-1)).toMatchObject({ type: 'table_row_focus', rowKey: '' })
  })

  it.each([
    'missing row',
    'pending deletion',
    'wrong kind',
    'missing kind',
    'empty kind',
    'wrong page',
    'new scope',
  ])('refuses opening with %s', async (change) => {
    const h = setup()
    if (change === 'pending deletion')
      h.rules.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: '1',
        reason: 'deleted',
      })
    if (change === 'wrong kind') h.kind.set(kindRow('second'))
    if (change === 'missing kind') h.kind.set(null)
    if (change === 'empty kind') h.kind.set(kindRow(''))
    if (change === 'wrong page') h.scopes.openPage(HilosPages.MODERATION_KIND)
    if (change === 'new scope')
      h.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
    expect(h.edit.open(change === 'missing row' ? 'missing' : '1')).toBe(false)
    await h.edit.save(h.run)
    expect(h.run).not.toHaveBeenCalled()
    expect(h.edit.opened.get()).toBe(false)
    expect(h.edit.target.get()).toBeNull()
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
  })

  it.each([0, -1, 1.5, 2])(
    'refuses invalid or mismatched focused identity %s',
    (id) => {
      const h = setup()
      h.window([{ ...wireRule(id), rowKey: '1' }])
      expect(h.edit.open('1')).toBe(false)
      expect(h.rules.controller.focusedRow.get()).toBeUndefined()
    },
  )

  it('automatically accepts untouched incoming, advances the baseline and clears the notice on typing', () => {
    const h = setup()
    h.edit.open('1')
    h.incoming('First incoming')
    expect(h.edit.form.get()).toEqual({ rule: 'First incoming' })
    expect(h.edit.notice.get()).toEqual({
      kind: 'updated',
      text: HILOS_ROW_EDIT_COPY.updated,
    })
    expect(h.edit.dirty.get()).toBe(false)
    h.edit.patchForm({ rule: 'My draft' })
    expect(h.edit.notice.get()).toBeNull()
    h.incoming('Second incoming')
    expect(h.edit.state.get().conflict).toBe(true)
    expect(h.edit.form.get().rule).toBe('My draft')
    expect(h.edit.notice.get()).toEqual({
      kind: 'conflict',
      text: 'Rule changed elsewhere to "Second incoming".',
    })
  })

  it('accepts identical concurrent text without a conflict or send', async () => {
    const h = setup()
    h.edit.open('1')
    h.edit.patchForm({ rule: 'Matching draft' })
    h.incoming('Matching draft')
    expect(h.edit.state.get().conflict).toBe(false)
    expect(h.edit.dirty.get()).toBe(false)
    expect(h.edit.form.get()).toEqual({ rule: 'Matching draft' })
    expect(h.edit.canSave.get()).toBe(false)
    await h.edit.save(h.run)
    expect(h.run).not.toHaveBeenCalled()
  })

  it.each(['keepMine', 'takeTheirs'] as const)(
    'uses the next accepted baseline and live expectedRule after %s',
    async (choice) => {
      const h = setup()
      h.edit.open('1')
      h.edit.patchForm({ rule: 'My draft' })
      h.incoming('Incoming one')
      expect(h.edit.canSave.get()).toBe(false)
      await h.edit.save(h.run)
      expect(h.run).not.toHaveBeenCalled()
      h.edit[choice]()
      expect(h.edit.form.get().rule).toBe(
        choice === 'keepMine' ? 'My draft' : 'Incoming one',
      )
      expect(h.edit.state.get().conflict).toBe(false)
      expect(h.edit.dirty.get()).toBe(choice === 'keepMine')
      h.edit.patchForm({ rule: '  Final raw\n\n' })
      // Returning to the opening text is still a new conflict after a choice.
      h.incoming('  Raw text\n')
      expect(h.edit.state.get().conflict).toBe(true)
      h.edit.keepMine()
      h.incoming('Latest saved')
      expect(h.edit.state.get().conflict).toBe(true)
      h.edit.keepMine()
      const pending = h.edit.save(h.run)
      expect(h.writes().at(-1)).toMatchObject({
        action: 'hilos_moderation_rule_update',
        data: {
          id: 1,
          kind: 'first',
          rule: '  Final raw\n\n',
          expectedRule: 'Latest saved',
        },
      })
      h.reply()
      await pending
      expect(h.edit.opened.get()).toBe(false)
    },
  )

  it.each(['', '  \n\t'])('never sends a blank rule %j', async (rule) => {
    const h = setup()
    h.edit.open('1')
    h.edit.patchForm({ rule })
    expect(h.edit.saveAvailable.get()).toBe(false)
    expect(h.edit.canSave.get()).toBe(false)
    await h.edit.save(h.run)
    expect(h.run).not.toHaveBeenCalled()
    expect(h.edit.opened.get()).toBe(true)
    expect(h.edit.form.get().rule).toBe(rule)
  })

  it.each(['left_set', 'moved_out'] as const)(
    'keeps focused text live after %s and Apply without changing the visible window',
    async (reason) => {
      const h = setup()
      h.edit.open('1')
      h.rules.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: '1',
        reason,
        row: wireRule(1, 'first', 'Outside window'),
      })
      expect(h.edit.form.get().rule).toBe('Outside window')
      expect(h.edit.state.get().gone).toBe(false)
      expect(h.rules.controller.pendingCount.get()).toBe(1)
      h.rules.controller.apply()
      expect(h.rules.controller.rows.get()[0].placeholder).toBe(true)
      expect(h.edit.state.get().gone).toBe(false)
      h.edit.patchForm({ rule: 'Edited outside' })
      const pending = h.edit.save(h.run)
      expect(h.writes().at(-1)?.data).toEqual({
        id: 1,
        kind: 'first',
        rule: 'Edited outside',
        expectedRule: 'Outside window',
      })
      h.reply()
      await pending
    },
  )

  it.each([false, true])(
    'keeps a deleted draft extractable and derives dirty (%s) from the accepted snapshot',
    async (dirty) => {
      const h = setup()
      h.edit.open('1')
      h.incoming('Accepted incoming')
      if (dirty) h.edit.patchForm({ rule: 'Draft to copy' })
      h.rules.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: '1',
        reason: 'deleted',
      })
      expect(h.edit.opened.get()).toBe(true)
      expect(h.edit.form.get().rule).toBe(
        dirty ? 'Draft to copy' : 'Accepted incoming',
      )
      expect(h.edit.dirty.get()).toBe(dirty)
      expect(h.edit.state.get().gone).toBe(true)
      expect(h.edit.saveLabel.get()).toBe(HILOS_ROW_EDIT_COPY.gone)
      expect(h.edit.notice.get()).toEqual({
        kind: 'deleted',
        text: HILOS_ROW_EDIT_COPY.deleted,
      })
      expect(h.edit.canSave.get()).toBe(false)
      await h.edit.save(h.run)
      expect(h.run).not.toHaveBeenCalled()
    },
  )

  it.each(['other focus', 'wrong id', 'wrong kind'])(
    'treats %s as gone without changing the captured target or draft',
    async (change) => {
      const h = setup()
      h.window([wireRule(), wireRule(2)])
      h.edit.open('1')
      const target = h.edit.target.get()
      h.edit.patchForm({ rule: 'My draft' })
      if (change === 'other focus') h.rules.controller.focusRow('2')
      else
        h.rules.controller.ingestDelta({
          kind: 'row_updated',
          rowKey: '1',
          row: {
            ...wireRule(
              change === 'wrong id' ? 2 : 1,
              change === 'wrong kind' ? 'second' : 'first',
            ),
            rowKey: '1',
          },
        })
      expect(h.edit.target.get()).toBe(target)
      expect(h.edit.form.get().rule).toBe('My draft')
      expect(h.edit.state.get().gone).toBe(true)
      await h.edit.save(h.run)
      expect(h.run).not.toHaveBeenCalled()
    },
  )

  it('locks immediately, sends raw text once and closes only on correlated success', async () => {
    const h = setup()
    h.edit.open('1')
    h.edit.patchForm({ rule: '  Saved raw\n\n' })
    const target = h.edit.target.get()
    const pending = h.edit.save(h.run)
    expect(h.edit.saving.get()).toBe(true)
    expect(h.edit.saveAvailable.get()).toBe(true)
    expect(h.edit.canSave.get()).toBe(false)
    h.edit.close()
    expect(h.edit.open('missing')).toBe(false)
    expect(h.edit.target.get()).toBe(target)
    await h.edit.save(h.run)
    expect(h.writes()).toEqual([
      {
        type: 'action',
        action: 'hilos_moderation_rule_update',
        data: {
          id: 1,
          kind: 'first',
          rule: '  Saved raw\n\n',
          expectedRule: '  Raw text\n',
        },
        requestId: expect.any(String),
      },
    ])
    expect(h.rules.controller.focusedRow.get()?.rule).toBe('  Raw text\n')
    h.reply('unrelated')
    await Promise.resolve()
    expect(h.edit.saving.get()).toBe(true)
    h.reply()
    await pending
    expect(h.edit.opened.get()).toBe(false)
    expect(h.edit.saving.get()).toBe(false)
    expect(h.edit.notice.get()).toBeNull()
    expect(h.edit.target.get()).toBeNull()
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
    expect(h.rules.controller.rows.get()[0].row?.rule).toBe('  Raw text\n')
  })

  it('preserves a refused draft and merges stale text before an explicit retry', async () => {
    const h = setup()
    h.edit.open('1')
    h.edit.patchForm({ rule: 'My refused draft' })
    const pending = h.edit.save(h.run)
    h.reply(undefined, false)
    await pending
    expect(h.failures).toMatchObject([
      { message: 'The rule changed. Review it and try again.' },
    ])
    expect(h.edit.opened.get()).toBe(true)
    expect(h.edit.saving.get()).toBe(false)
    expect(h.edit.form.get().rule).toBe('My refused draft')
    h.incoming('Committed elsewhere')
    expect(h.edit.state.get().conflict).toBe(true)
    await h.edit.save(h.run)
    expect(h.writes()).toHaveLength(1)
    h.edit.keepMine()
    const retry = h.edit.save(h.run)
    expect(h.writes().at(-1)?.data).toEqual({
      id: 1,
      kind: 'first',
      rule: 'My refused draft',
      expectedRule: 'Committed elsewhere',
    })
    h.reply()
    await retry
    expect(h.edit.opened.get()).toBe(false)
  })

  it('blocks stale provenance even while the edited row remains live', async () => {
    const h = setup()
    h.window([wireRule(), wireRule(2)])
    h.edit.open('1')
    h.edit.patchForm({ rule: 'My draft' })
    h.rules.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: '2',
      row: wireRule(2, 'second'),
    })
    expect(h.rules.current.get()).toBe(false)
    expect(h.edit.state.get().gone).toBe(false)
    expect(h.edit.saveAvailable.get()).toBe(false)
    await h.edit.save(h.run)
    expect(h.run).not.toHaveBeenCalled()
    expect(h.edit.opened.get()).toBe(true)
  })

  it.each(['kind', 'scope', 'departure', 'missing kind', 'dispose'])(
    'releases focus and invalidates an in-flight save on %s',
    async (change) => {
      const h = setup()
      h.edit.open('1')
      h.edit.patchForm({ rule: 'Pending draft' })
      const pending = h.edit.save(h.run)
      const oldId = String(h.writes().at(-1)?.requestId)
      if (change === 'kind') h.kind.set(kindRow('second'))
      if (change === 'scope')
        h.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
      if (change === 'departure') h.scopes.dropPage()
      if (change === 'missing kind') h.kind.set(null)
      if (change === 'dispose') h.edit.dispose()
      expect(h.edit.opened.get()).toBe(false)
      expect(h.edit.saving.get()).toBe(false)
      expect(h.edit.target.get()).toBeNull()
      expect(h.rules.controller.focusedRow.get()).toBeUndefined()
      h.reply(oldId)
      await pending
      expect(h.edit.opened.get()).toBe(false)
    },
  )

  it.each([true, false])(
    'ignores late old success/refusal (%s) during a replacement save',
    async (success) => {
      const h = setup()
      h.edit.open('1')
      h.edit.patchForm({ rule: 'Old draft' })
      const old = h.edit.save(h.run)
      const oldId = String(h.writes().at(-1)?.requestId)
      h.edit.dispose()
      h.edit.dispose()
      h.edit.start()
      h.edit.start()
      h.window([wireRule(2)])
      expect(h.edit.open('2')).toBe(true)
      h.edit.patchForm({ rule: 'Replacement draft' })
      const fresh = h.edit.save(h.run)
      const freshId = String(h.writes().at(-1)?.requestId)
      h.reply(oldId, success)
      await old
      expect(h.edit.opened.get()).toBe(true)
      expect(h.edit.target.get()?.id).toBe(2)
      expect(h.edit.saving.get()).toBe(true)
      expect(h.rules.controller.focusedRow.get()?.id).toBe(2)
      h.reply(freshId)
      await fresh
      expect(h.edit.opened.get()).toBe(false)
    },
  )

  it('keeps start and dispose idempotent, with no idle release of another focus', () => {
    const h = setup()
    h.edit.open('1')
    const target = h.edit.target.get()
    h.edit.start()
    h.edit.start()
    expect(h.edit.target.get()).toBe(target)
    h.edit.close()
    h.edit.close()
    h.rules.controller.focusRow('1')
    h.edit.dispose()
    h.edit.dispose()
    expect(h.rules.controller.focusedRow.get()?.id).toBe(1)
    h.edit.start()
    h.edit.open('1')
    h.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
    expect(h.edit.opened.get()).toBe(false)
  })

  it('releases saving and preserves the draft if dispatch throws', async () => {
    const h = setup()
    h.edit.open('1')
    h.edit.patchForm({ rule: 'Draft to keep' })
    vi.spyOn(h.actions, 'dispatch').mockImplementation(() => {
      throw new Error('Dispatch failed')
    })
    await expect(h.edit.save(h.run)).rejects.toThrow('Dispatch failed')
    expect(h.edit.saving.get()).toBe(false)
    expect(h.edit.opened.get()).toBe(true)
    expect(h.edit.form.get().rule).toBe('Draft to keep')
    expect(h.edit.canSave.get()).toBe(true)
  })
})

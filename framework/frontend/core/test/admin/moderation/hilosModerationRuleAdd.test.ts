import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosModerationRuleAdd,
  HILOS_MODERATION_RULE_ADD_ACTION,
  HILOS_MODERATION_RULE_ADD_COPY,
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
import { createSignal } from '../../../src/state/signal.js'

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
  const current = createSignal(true)
  const context: HilosModerationRulesContext = { connection, scopes, actions }
  const add = createHilosModerationRuleAdd(context, source, current)
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
  add.start()
  cleanups.push(() => {
    add.dispose()
    connection.close()
  })
  return {
    add,
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
            action: 'hilos_moderation_rule_add',
            ...(success
              ? { message: 'Rule added to "First kind".' }
              : { reason: 'This rule could not be added.' }),
          },
        }),
      })
    },
  }
}

describe('createHilosModerationRuleAdd', () => {
  it('shares the approved action and form words', () => {
    expect(HILOS_MODERATION_RULE_ADD_ACTION).toBe('hilos_moderation_rule_add')
    expect(HILOS_MODERATION_RULE_ADD_COPY).toMatchObject({
      open: 'Add rule',
      ruleLabel: 'Rule',
      save: 'Save',
      cancel: 'Cancel',
      refusalTitle: "Couldn't add the rule",
    })
    expect(HILOS_MODERATION_RULE_ADD_COPY.title('Profile photo')).toBe(
      'Add rule · Profile photo',
    )
  })

  it('opens only over a current rules page with a delivered kind', () => {
    const h = setup()
    expect(h.add.openState.get()).toBe(false)
    expect(h.add.target.get()).toBeNull()
    h.scopes.dropPage()
    h.add.open()
    expect(h.add.openState.get()).toBe(false)
    h.scopes.openPage(HilosPages.MODERATION_KIND)
    h.add.open()
    expect(h.add.openState.get()).toBe(false)
    h.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
    h.source.set(null)
    h.add.open()
    expect(h.add.openState.get()).toBe(false)
    h.source.set(kindRow(''))
    h.add.open()
    expect(h.add.openState.get()).toBe(false)
    h.source.set(kindRow())
    h.current.set(false)
    h.add.open()
    expect(h.add.openState.get()).toBe(false)
    h.current.set(true)
    h.add.open()
    expect(h.add.openState.get()).toBe(true)
    expect(h.add.target.get()).toEqual({ kind: 'first', title: 'First kind' })
    expect(h.add.rule.get()).toBe('')
  })

  it('keeps empty and whitespace drafts unsendable, with raw dirty state', async () => {
    const h = setup()
    h.add.setRule('Closed')
    expect(h.add.rule.get()).toBe('')
    for (const rule of ['', ' ', '\n\t  ']) {
      h.add.open()
      h.add.setRule(rule)
      expect(h.add.rule.get()).toBe(rule)
      expect(h.add.dirty.get()).toBe(rule !== '')
      expect(h.add.submitAvailable.get()).toBe(false)
      expect(h.add.canSubmit.get()).toBe(false)
      await h.add.submit(h.run)
    }
    expect(h.run).not.toHaveBeenCalled()
    expect(h.sent).toEqual([])
    h.add.setRule('Text')
    expect(h.add.dirty.get()).toBe(true)
    expect(h.add.canSubmit.get()).toBe(true)
    h.add.setRule('')
    expect(h.add.dirty.get()).toBe(false)
    expect(h.add.canSubmit.get()).toBe(false)
  })

  it('dispatches captured kind and raw multiline text, closing only on its success reply', async () => {
    const h = setup()
    h.add.open()
    h.add.setRule('  Reject phone numbers.\n\n  Keep punctuation: +48.\n')
    h.source.set(kindRow('first', 'Updated title'))
    expect(h.add.target.get()).toEqual({ kind: 'first', title: 'First kind' })
    const pending = h.add.submit(h.run)
    expect(h.sent).toEqual([
      {
        type: 'action',
        action: 'hilos_moderation_rule_add',
        data: {
          kind: 'first',
          rule: '  Reject phone numbers.\n\n  Keep punctuation: +48.\n',
        },
        requestId: expect.any(String),
      },
    ])
    expect(h.add.openState.get()).toBe(true)
    expect(h.add.saving.get()).toBe(true)
    h.reply('unrelated-request')
    await Promise.resolve()
    expect(h.add.openState.get()).toBe(true)
    expect(h.add.saving.get()).toBe(true)
    h.reply()
    await pending
    expect(h.add.openState.get()).toBe(false)
    expect(h.add.saving.get()).toBe(false)
    expect(h.add.target.get()).toBeNull()
    expect(h.add.rule.get()).toBe('')
    expect(h.add.dirty.get()).toBe(false)
    expect(h.add.submitAvailable.get()).toBe(false)
  })

  it('locks repeated submit, input, opening and closing throughout saving', async () => {
    const h = setup()
    h.add.open()
    h.add.setRule('Original draft')
    const pending = h.add.submit(h.run)
    expect(h.add.canSubmit.get()).toBe(false)
    expect(h.add.submitAvailable.get()).toBe(true)
    h.add.close()
    h.add.open()
    h.add.setRule('Changed')
    await h.add.submit(h.run)
    expect(h.sent).toHaveLength(1)
    expect(h.run).toHaveBeenCalledTimes(1)
    expect(h.add.openState.get()).toBe(true)
    expect(h.add.rule.get()).toBe('Original draft')
    h.reply()
    await pending
  })

  it('retains a refused draft and sends again only on an explicit submit', async () => {
    const h = setup()
    h.add.open()
    h.add.setRule('  Draft\n')
    const pending = h.add.submit(h.run)
    h.reply(undefined, false)
    await pending
    expect(h.failures).toMatchObject([
      { message: 'This rule could not be added.' },
    ])
    expect(h.add.openState.get()).toBe(true)
    expect(h.add.rule.get()).toBe('  Draft\n')
    expect(h.add.target.get()).toEqual({ kind: 'first', title: 'First kind' })
    expect(h.add.saving.get()).toBe(false)
    expect(h.add.canSubmit.get()).toBe(true)
    expect(h.sent).toHaveLength(1)
    const retry = h.add.submit(h.run)
    expect(h.sent).toHaveLength(2)
    h.reply()
    await retry
    expect(h.add.openState.get()).toBe(false)
  })

  it('leaves an open draft unsendable while its window is no longer current', async () => {
    const h = setup()
    h.add.open()
    h.add.setRule('Draft')
    h.current.set(false)
    expect(h.add.submitAvailable.get()).toBe(false)
    expect(h.add.canSubmit.get()).toBe(false)
    await h.add.submit(h.run)
    expect(h.sent).toEqual([])
    expect(h.add.openState.get()).toBe(true)
    expect(h.add.rule.get()).toBe('Draft')
    h.current.set(true)
    expect(h.add.canSubmit.get()).toBe(true)
  })

  it('discards on close and starts each opening empty', async () => {
    const h = setup()
    h.add.open()
    h.add.setRule('Draft')
    h.add.close()
    expect(h.add.openState.get()).toBe(false)
    expect(h.add.rule.get()).toBe('')
    expect(h.add.target.get()).toBeNull()
    expect(h.add.dirty.get()).toBe(false)
    await h.add.submit(h.run)
    expect(h.sent).toEqual([])
    h.add.open()
    expect(h.add.openState.get()).toBe(true)
    expect(h.add.rule.get()).toBe('')
  })

  it.each(['kind', 'scope', 'departure', 'missing kind'] as const)(
    'resets the session on %s change even while saving',
    async (change) => {
      const h = setup()
      h.add.open()
      h.add.setRule('Old draft')
      const pending = h.add.submit(h.run)
      if (change === 'kind') h.source.set(kindRow('second', 'Second kind'))
      if (change === 'scope')
        h.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
      if (change === 'departure') h.scopes.dropPage()
      if (change === 'missing kind') h.source.set(null)
      expect(h.add.openState.get()).toBe(false)
      expect(h.add.target.get()).toBeNull()
      expect(h.add.rule.get()).toBe('')
      expect(h.add.dirty.get()).toBe(false)
      expect(h.add.saving.get()).toBe(false)
      expect(h.add.canSubmit.get()).toBe(false)
      h.reply()
      await pending
      expect(h.add.openState.get()).toBe(false)
    },
  )

  it.each([
    { change: 'kind', success: true },
    { change: 'kind', success: false },
    { change: 'scope', success: true },
    { change: 'scope', success: false },
  ])(
    'ignores an old reply ($success) after $change change during a new save',
    async ({ change, success }) => {
      const h = setup()
      h.add.open()
      h.add.setRule('Old draft')
      const old = h.add.submit(h.run)
      const oldId = String(h.sent[0].requestId)
      if (change === 'scope')
        h.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
      if (change === 'kind') h.source.set(kindRow('second', 'Second kind'))
      const kind = change === 'kind' ? 'second' : 'first'
      const title = change === 'kind' ? 'Second kind' : 'First kind'
      h.add.open()
      h.add.setRule('New draft')
      const fresh = h.add.submit(h.run)
      expect(h.sent[1].data).toEqual({ kind, rule: 'New draft' })
      h.reply(oldId, success)
      await old
      expect(h.add.openState.get()).toBe(true)
      expect(h.add.target.get()).toEqual({ kind, title })
      expect(h.add.rule.get()).toBe('New draft')
      expect(h.add.saving.get()).toBe(true)
      expect(h.add.canSubmit.get()).toBe(false)
      h.reply()
      await fresh
      expect(h.add.openState.get()).toBe(false)
      expect(h.add.saving.get()).toBe(false)
    },
  )

  it('keeps start and dispose idempotent and invalidates a disposed pending attempt', async () => {
    const h = setup()
    h.add.open()
    h.add.setRule('Old draft')
    h.add.start()
    h.add.start()
    expect(h.add.rule.get()).toBe('Old draft')
    const old = h.add.submit(h.run)
    const oldId = String(h.sent[0].requestId)
    h.add.dispose()
    h.add.dispose()
    expect(h.add.openState.get()).toBe(false)
    expect(h.add.saving.get()).toBe(false)
    expect(h.add.rule.get()).toBe('')
    h.source.set(kindRow('second', 'Second kind'))
    h.add.start()
    h.add.start()
    h.add.open()
    h.add.setRule('Fresh draft')
    h.reply(oldId)
    await old
    expect(h.add.openState.get()).toBe(true)
    expect(h.add.rule.get()).toBe('Fresh draft')
    expect(h.add.saving.get()).toBe(false)
    expect(h.add.canSubmit.get()).toBe(true)
    h.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
    expect(h.add.openState.get()).toBe(false)
  })

  it('releases saving but keeps the draft if dispatch throws', async () => {
    const h = setup()
    h.add.open()
    h.add.setRule('Draft')
    vi.spyOn(h.actions, 'dispatch').mockImplementation(() => {
      throw new Error('Dispatch failed')
    })
    await expect(h.add.submit(h.run)).rejects.toThrow('Dispatch failed')
    expect(h.add.openState.get()).toBe(true)
    expect(h.add.saving.get()).toBe(false)
    expect(h.add.rule.get()).toBe('Draft')
    expect(h.add.canSubmit.get()).toBe(true)
  })
})

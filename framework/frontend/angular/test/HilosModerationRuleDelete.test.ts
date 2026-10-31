import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { signal } from '@angular/core'
import {
  ActionLifecycle,
  DEFAULT_SPINNER_DELAY_MS,
  HilosConnection,
  HilosPages,
  ScopeManager,
  computedSignal,
  createSignal,
  hilosToasts,
  type WebSocketLike,
} from '@hilos/core/base'
import {
  createHilosModerationRuleDelete,
  createHilosModerationRules,
  type HilosModerationKindRow,
} from '@hilos/core/admin/moderation'
import { HILOS_TAKEOVER_VIEW_ONLY } from '@hilos/angular/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import { HilosModerationRuleDelete } from '../src/admin/moderation/HilosModerationRuleDelete.js'

let fixture: ComponentFixture<HilosModerationRuleDelete> | undefined
const cleanups: Array<() => void> = []
// Drain promise chains on a real task even while the spinner clock is mocked.
const nextTask = globalThis.setTimeout
afterEach(() => {
  fixture?.destroy()
  fixture = undefined
  for (const off of cleanups.splice(0)) off()
  TestBed.resetTestingModule()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  hilosToasts.clear()
  vi.useRealTimers()
})

/** Real core windows, focus, action lifecycle and modal; only backend delivery is scripted. */
function harness(mode: 'admin' | 'viewer' | 'takeover' = 'admin') {
  const listeners = new Map<string, (event: { data?: unknown }) => void>()
  const sent: Array<Record<string, unknown>> = []
  const socket: WebSocketLike = {
    send: (text) => sent.push(JSON.parse(String(text))),
    close: () => {},
    addEventListener: (event, listener) => listeners.set(event, listener),
  }
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: () => socket,
  })
  const actions = new ActionLifecycle(connection, { timeoutMs: 1000 })
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MODERATION_KIND_RULES)
  const kind = createSignal<HilosModerationKindRow | null>({
    kind: 'first',
    title: 'First kind',
    owner: 'framework',
    input: 'text',
    model: 'model',
    profile: 'text',
    ownRuleCount: 1,
    reasons: [],
    defaultRules: ['Built-in'],
  })
  const context = { connection, scopes, actions }
  const rules = createHilosModerationRules(
    context,
    computedSignal(() => kind.get()?.kind ?? ''),
  )
  const window = createHilosModerationRuleDelete(context, kind, rules)
  function wire(rule = 'Saved rule') {
    return {
      rowKey: '1',
      slots: { rule: { id: 1, kind: kind.get()!.kind, rule } },
    }
  }
  function answer(rule = 'Saved rule') {
    rules.controller.ingestWindow([wire(rule)], 1, true, null, null, 25, 0)
  }
  connection.connect()
  listeners.get('open')?.({})
  rules.start()
  answer()
  window.start()
  const opener = document.createElement('button')
  opener.textContent = 'Delete rule'
  opener.onclick = () => window.open('1')
  document.body.append(opener)
  TestBed.configureTestingModule({
    providers: [
      { provide: HILOS_ADMIN_VIEW_MODE, useValue: signal(mode === 'viewer') },
      {
        provide: HILOS_TAKEOVER_VIEW_ONLY,
        useValue: signal(mode === 'takeover'),
      },
    ],
  })
  fixture = TestBed.createComponent(HilosModerationRuleDelete)
  fixture.componentRef.setInput('remove', window)
  document.body.append(fixture.nativeElement)
  fixture.detectChanges()
  cleanups.push(() => {
    window.dispose()
    rules.dispose()
    connection.close()
    opener.remove()
  })
  const writes = () => sent.filter((frame) => frame.type === 'action')
  return {
    window,
    context,
    kind,
    rules,
    writes,
    opener,
    answer,
    async open() {
      opener.focus()
      opener.click()
      await settled()
    },
    incoming(rule: string) {
      rules.controller.ingestDelta({
        kind: 'row_updated',
        rowKey: '1',
        row: wire(rule),
      })
    },
    gone() {
      rules.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: '1',
        reason: 'deleted',
      })
    },
    reply(success = true, requestId = String(writes().at(-1)?.requestId)) {
      listeners.get('message')?.({
        data: JSON.stringify({
          type: success ? 'action_success' : 'action_error',
          requestId,
          outcome: success ? 'success' : 'fail',
          data: {
            action: 'hilos_moderation_rule_delete',
            ...(success
              ? { message: 'Server success.' }
              : {
                  reason: 'Rule refused.',
                  errorType: 'ValidationException',
                  errorDetail: 'Refusal details.',
                }),
          },
        }),
      })
    },
  }
}
function el<T extends HTMLElement = HTMLElement>(id: string): T {
  const node = document.querySelector<T>(`[data-id="${id}"]`)
  if (!node) throw new Error(`Missing ${id}`)
  return node
}
function save(): HTMLButtonElement {
  return el('hilos-moderation-rule-delete-confirm')
}
async function settled(): Promise<void> {
  await new Promise<void>((resolve) => nextTask(resolve, 0))
  fixture?.detectChanges()
}
describe('HilosModerationRuleDelete', () => {
  it('names the dialog, reserves refusal room and restores focus on close', async () => {
    const h = harness()
    await h.open()
    expect(el('modal').getAttribute('role')).toBe('dialog')
    expect(el('modal').getAttribute('aria-label')).toBe('Delete rule')
    expect(el('hilos-action-error-slot')).toBeTruthy()
    expect(document.activeElement).toBe(el('modal'))
    expect(el('hilos-moderation-rule-delete-rule').textContent?.trim()).toBe(
      'Saved rule',
    )
    el('hilos-moderation-rule-delete-cancel').click()
    await settled()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.activeElement).toBe(h.opener)
  })
  it.each(['changed', 'deleted'])(
    'holds the exact snapshot and blocks %s targets',
    async (change) => {
      const h = harness()
      await h.open()
      const target = h.window.target.get()
      if (change === 'changed') h.incoming('New saved text')
      else h.gone()
      await settled()
      expect(h.window.target.get()).toBe(target)
      expect(el('hilos-moderation-rule-delete-rule').textContent?.trim()).toBe(
        'Saved rule',
      )
      expect(el('hilos-moderation-rule-delete-notice').textContent).toContain(
        change === 'changed'
          ? 'The rule changed.'
          : 'The rule no longer exists.',
      )
      expect(save().disabled).toBe(true)
      save().click()
      expect(h.writes()).toEqual([])
    },
  )

  it('sends the immutable expectedRule and closes only for the matching success', async () => {
    const h = harness()
    await h.open()
    save().click()
    expect(h.writes()).toEqual([
      {
        type: 'action',
        action: 'hilos_moderation_rule_delete',
        data: { id: 1, kind: 'first', expectedRule: 'Saved rule' },
        requestId: expect.any(String),
      },
    ])
    h.reply(true, 'unrelated')
    await settled()
    expect(h.window.openState.get()).toBe(true)
    h.reply()
    await settled()
    expect(h.window.openState.get()).toBe(false)
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
    expect(
      hilosToasts.toasts
        .get()
        .map(({ message, severity }) => [message, severity]),
    ).toEqual([['Server success.', 'success']])
  })
  it('locks repeat submits, fields and close gestures immediately before the spinner', async () => {
    vi.useFakeTimers()
    const h = harness()
    await h.open()

    const button = save()
    button.focus()
    button.click()
    await settled()
    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(button.disabled).toBe(false)
    expect(document.activeElement).toBe(button)
    expect(
      document.querySelector('[data-id="loading-button-spinner"]'),
    ).toBeNull()

    expect(
      el<HTMLButtonElement>('hilos-moderation-rule-delete-cancel').disabled,
    ).toBe(true)
    expect(el<HTMLButtonElement>('modal-close').disabled).toBe(true)
    button.click()

    el('modal-close').click()
    el('modal').click()
    el('modal').dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await settled()
    expect(h.writes()).toHaveLength(1)
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    await vi.advanceTimersByTimeAsync(DEFAULT_SPINNER_DELAY_MS)
    await settled()
    expect(el('loading-button-spinner')).toBeTruthy()
    h.reply()
    await settled()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('retains refusal and draft without a toast or automatic retry', async () => {
    const h = harness()
    await h.open()

    const slot = el('hilos-action-error-slot')
    save().click()
    h.reply(false)
    await settled()
    expect(el('hilos-action-error-slot')).toBe(slot)
    expect(el('hilos-action-error').textContent).toContain('Rule refused.')
    expect(el('hilos-moderation-rule-delete-rule').textContent?.trim()).toBe(
      'Saved rule',
    )
    expect(h.writes()).toHaveLength(1)
    expect(hilosToasts.toasts.get()).toEqual([])
    el('hilos-action-error-details').click()
    await settled()
    expect(document.body.textContent).toContain('Refusal details.')
    el('hilos-action-error-close').click()
    await settled()
    save().click()
    h.reply()
    await settled()
    expect(h.writes()).toHaveLength(2)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it.each(['viewer', 'takeover'] as const)(
    'allows local work but guards click and submit for %s',
    async (mode) => {
      const h = harness(mode)
      await h.open()

      expect(save().disabled).toBe(true)
      expect(save().getAttribute('aria-describedby')).toBe(
        mode === 'viewer'
          ? 'hilos-view-mode-strip-text'
          : 'hilos-impersonation-strip-text',
      )
      save().click()

      await settled()
      expect(h.writes()).toEqual([])
      expect(
        el<HTMLButtonElement>('hilos-moderation-rule-delete-cancel').disabled,
      ).toBe(false)
    },
  )

  it.each([true, false])(
    'isolates late success/refusal (%s) after kind replacement',
    async (success) => {
      const h = harness()
      await h.open()

      save().click()
      const oldId = String(h.writes()[0].requestId)
      h.kind.set({ ...h.kind.get()!, kind: 'second', title: 'Second kind' })
      h.answer()
      await settled()
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      await h.open()

      save().click()
      await settled()
      h.reply(success, oldId)
      await settled()
      expect(save().getAttribute('aria-busy')).toBe('true')
      expect(h.window.target.get()?.kind).toBe('second')
      expect(
        document.querySelector('[data-id="hilos-action-error"]'),
      ).toBeNull()
      expect(hilosToasts.toasts.get()).toEqual([])
      h.reply(false)
      await settled()
      expect(el('hilos-action-error').textContent).toContain('Rule refused.')
    },
  )
  it('releases an old scope and keeps its late response out of the replacement opening', async () => {
    const h = harness()
    await h.open()

    save().click()
    const oldId = String(h.writes()[0].requestId)
    h.context.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
    h.answer()
    await settled()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
    await h.open()
    h.reply(false, oldId)
    await settled()
    expect(document.querySelector('[data-id="hilos-action-error"]')).toBeNull()
    expect(save().hasAttribute('aria-busy')).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('does not publish a late success after the component and its factory are destroyed', async () => {
    const h = harness()
    await h.open()

    save().click()
    const root = fixture!.nativeElement as HTMLElement
    fixture!.destroy()
    root.remove()
    fixture = undefined
    h.window.dispose()
    h.reply()
    await settled()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })
})

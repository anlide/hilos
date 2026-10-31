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
  createHilosModerationRuleEdit,
  createHilosModerationRules,
  type HilosModerationKindRow,
} from '@hilos/core/admin/moderation'
import { HILOS_TAKEOVER_VIEW_ONLY } from '@hilos/angular/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import { HilosModerationRuleEdit } from '../src/admin/moderation/HilosModerationRuleEdit.js'

let fixture: ComponentFixture<HilosModerationRuleEdit> | undefined
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
  const window = createHilosModerationRuleEdit(context, kind, rules)
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
  opener.textContent = 'Edit rule'
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
  fixture = TestBed.createComponent(HilosModerationRuleEdit)
  fixture.componentRef.setInput('edit', window)
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
            action: 'hilos_moderation_rule_update',
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
  return el('hilos-moderation-rule-edit-submit')
}
async function settled(): Promise<void> {
  await new Promise<void>((resolve) => nextTask(resolve, 0))
  fixture?.detectChanges()
}
function field(): HTMLTextAreaElement {
  return document.querySelector('textarea')!
}
async function input(value: string): Promise<void> {
  field().value = value
  field().dispatchEvent(new Event('input', { bubbles: true }))
  await settled()
}
function submit(): void {
  field().form!.dispatchEvent(
    new Event('submit', { bubbles: true, cancelable: true }),
  )
}
describe('HilosModerationRuleEdit', () => {
  it('names the dialog, reserves refusal room and restores focus on close', async () => {
    const h = harness()
    await h.open()
    expect(el('modal').getAttribute('role')).toBe('dialog')
    expect(el('modal').getAttribute('aria-label')).toBe(
      'Edit rule · First kind',
    )
    expect(el('hilos-action-error-slot')).toBeTruthy()
    expect(document.activeElement).toBe(field())
    expect(
      document.querySelector(`label[for="${field().id}"]`)?.textContent?.trim(),
    ).toBe('Rule')
    el('hilos-moderation-rule-edit-cancel').click()
    await settled()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.activeElement).toBe(h.opener)
  })
  it('accepts untouched updates, resolves both conflict choices and saves raw text against the accepted value', async () => {
    const h = harness()
    await h.open()
    for (const value of ['Saved rule', '', ' \n\t']) {
      await input(value)
      expect(save().disabled).toBe(true)
      submit()
    }
    expect(h.writes()).toEqual([])
    await input('Saved rule')
    h.incoming('Updated')
    await settled()
    expect(field().value).toBe('Updated')
    expect(el('hilos-moderation-rule-edit-notice').textContent).toContain(
      'Updated just now',
    )
    await input('Mine')
    h.incoming('Theirs')
    await settled()
    expect(el('conflict-badge')).toBeTruthy()
    expect(save().disabled).toBe(true)
    submit()
    expect(h.writes()).toEqual([])
    el('conflict-accept-theirs').click()
    await settled()
    expect(field().value).toBe('Theirs')
    expect(save().disabled).toBe(true)
    const raw = '  raw\n\nrule\n'
    await input(raw)
    h.incoming('New incoming')
    await settled()
    el('conflict-accept-mine').click()
    await settled()
    expect(field().value).toBe(raw)
    save().click()
    expect(h.writes()).toEqual([
      {
        type: 'action',
        action: 'hilos_moderation_rule_update',
        data: { id: 1, kind: 'first', rule: raw, expectedRule: 'New incoming' },
        requestId: expect.any(String),
      },
    ])
    h.reply(true, 'unrelated')
    await settled()
    expect(h.window.opened.get()).toBe(true)
    h.reply()
    await settled()
    expect(h.window.opened.get()).toBe(false)
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
    expect(
      hilosToasts.toasts
        .get()
        .map(({ message, severity }) => [message, severity]),
    ).toEqual([['Server success.', 'success']])
  })

  it('keeps a deleted draft extractable and blocks saving while the discard layer can keep or release it', async () => {
    const h = harness()
    await h.open()
    await input('Copy my draft')
    h.gone()
    await settled()
    const textarea = field()
    expect(save().textContent?.trim()).toBe('Deleted')
    expect(save().disabled).toBe(true)
    expect(field().value).toBe('Copy my draft')
    submit()
    expect(h.writes()).toEqual([])
    el('hilos-moderation-rule-edit-cancel').click()
    await settled()
    expect(el('modal-confirm').getAttribute('aria-label')).toBe(
      'Discard changes?',
    )
    el('modal-confirm-cancel').click()
    await settled()
    expect(field()).toBe(textarea)
    field().select()
    expect(
      field().value.slice(field().selectionStart, field().selectionEnd),
    ).toBe('Copy my draft')
    el('modal-close').click()
    await settled()
    el('modal-confirm-discard').click()
    await settled()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })
  it('locks repeat submits, fields and close gestures immediately before the spinner', async () => {
    vi.useFakeTimers()
    const h = harness()
    await h.open()
    await input('Pending draft')
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
    expect(field().matches(':disabled')).toBe(true)
    expect(
      el<HTMLButtonElement>('hilos-moderation-rule-edit-cancel').disabled,
    ).toBe(true)
    expect(el<HTMLButtonElement>('modal-close').disabled).toBe(true)
    button.click()
    submit()
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
    await input('Refused draft')
    const slot = el('hilos-action-error-slot')
    save().click()
    h.reply(false)
    await settled()
    expect(el('hilos-action-error-slot')).toBe(slot)
    expect(el('hilos-action-error').textContent).toContain('Rule refused.')
    expect(field().value).toBe('Refused draft')
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
      await input('Local draft')
      h.incoming('Incoming')
      await settled()
      el('conflict-accept-mine').click()
      await settled()
      expect(field().value).toBe('Local draft')
      expect(field().matches(':disabled')).toBe(false)
      expect(save().disabled).toBe(true)
      expect(save().getAttribute('aria-describedby')).toBe(
        mode === 'viewer'
          ? 'hilos-view-mode-strip-text'
          : 'hilos-impersonation-strip-text',
      )
      save().click()
      submit()
      await settled()
      expect(h.writes()).toEqual([])
      expect(
        el<HTMLButtonElement>('hilos-moderation-rule-edit-cancel').disabled,
      ).toBe(false)
    },
  )

  it.each([true, false])(
    'isolates late success/refusal (%s) after kind replacement',
    async (success) => {
      const h = harness()
      await h.open()
      await input('Old draft')
      save().click()
      const oldId = String(h.writes()[0].requestId)
      h.kind.set({ ...h.kind.get()!, kind: 'second', title: 'Second kind' })
      h.answer()
      await settled()
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      await h.open()
      await input('Replacement draft')
      save().click()
      await settled()
      h.reply(success, oldId)
      await settled()
      expect(save().getAttribute('aria-busy')).toBe('true')
      expect(field().value).toBe('Replacement draft')
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
    await input('Old draft')
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
    await input('Old draft')
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

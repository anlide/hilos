import { flushPromises, mount } from '@vue/test-utils'
import {
  ActionLifecycle,
  DEFAULT_SPINNER_DELAY_MS,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
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
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import {
  hilosAdminViewModeKey,
  hilosTakeoverViewOnlyKey,
} from '../../hilosAdminViewMode.js'
import HilosActionError from '../../HilosActionError.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import HilosModerationRuleDelete from './HilosModerationRuleDelete.vue'

const cleanups: Array<() => void> = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0)) cleanup()
  hilosToasts.clear()
  vi.useRealTimers()
})

function kindRow(kind = 'first', title = 'First kind'): HilosModerationKindRow {
  return {
    kind,
    title,
    owner: 'framework',
    input: 'text',
    model: 'model',
    ownRuleCount: 1,
    profile: 'text',
    reasons: [],
    defaultRules: ['Built-in rule'],
  }
}

function wireRule(rule = '  Raw text\n', kind = 'first') {
  return { rowKey: '1', slots: { rule: { id: 1, kind, rule } } }
}

/** Real focus source, lifecycle and modal primitives; only socket and page rows are scripted. */
function harness(mode: 'admin' | 'viewer' | 'takeover' = 'admin') {
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
  const context = { connection, scopes, actions }
  const rules = createHilosModerationRules(
    context,
    computedSignal(() => source.get()?.kind ?? ''),
  )
  const remove = createHilosModerationRuleDelete(context, source, rules)
  connection.connect()
  listeners.get('open')?.({})
  rules.start()
  function window(rule = '  Raw text\n', kind = 'first'): void {
    rules.controller.ingestWindow(
      [wireRule(rule, kind)],
      1,
      true,
      null,
      null,
      25,
      0,
    )
  }
  window()
  remove.start()
  const opener = document.createElement('button')
  opener.textContent = 'Delete rule'
  opener.onclick = () => remove.open('1')
  document.body.append(opener)
  const view = mount(HilosModerationRuleDelete, {
    props: { remove },
    attachTo: document.body,
    global: {
      provide: {
        [hilosAdminViewModeKey as symbol]: ref(mode === 'viewer'),
        [hilosTakeoverViewOnlyKey as symbol]: ref(mode === 'takeover'),
      },
    },
  })
  cleanups.push(() => {
    view.unmount()
    remove.dispose()
    rules.dispose()
    connection.close()
    opener.remove()
  })
  return {
    remove,
    rules,
    source,
    scopes,
    sent,
    view,
    opener,
    window,
    requests: () => sent.filter((frame) => frame.type === 'action'),
    async open() {
      opener.focus()
      opener.click()
      await settled()
    },
    reply(
      requestId = String(
        sent.filter((frame) => frame.type === 'action').at(-1)?.requestId,
      ),
      success = true,
      message: string | null = 'Rule removed from "First kind".',
    ) {
      listeners.get('message')?.({
        data: JSON.stringify({
          type: success ? 'action_success' : 'action_error',
          requestId,
          outcome: success ? 'success' : 'fail',
          data: {
            action: 'hilos_moderation_rule_delete',
            ...(success
              ? message === null
                ? {}
                : { message }
              : {
                  reason: 'This rule could not be removed.',
                  errorType: 'DatabaseException',
                  errorDetail: 'The database refused the deletion.',
                }),
          },
        }),
      })
    },
  }
}

function element<T extends HTMLElement = HTMLElement>(id: string): T {
  const found = document.querySelector<T>(`[data-id="${id}"]`)
  if (found === null) throw new Error(`Missing ${id}`)
  return found
}

function confirm(): HTMLButtonElement {
  return element('hilos-moderation-rule-delete-confirm')
}

async function settled(): Promise<void> {
  await flushPromises()
  await nextTick()
}

describe('HilosModerationRuleDelete', () => {
  it('mounts the named dialog, plain readable snapshot and permanent refusal and notice slots', async () => {
    const h = harness()
    const raw = '<b>Plain rule</b> '.repeat(200) + '\n  Keep spaces.\n'
    h.window(raw)
    await h.open()
    expect(h.view.findComponent(HilosModal).exists()).toBe(true)
    expect(h.view.findComponent(HilosActionError).exists()).toBe(true)
    expect(h.view.findComponent(HilosEditNotice).exists()).toBe(true)
    expect(h.view.findComponent(LoadingButton).exists()).toBe(true)
    const modal = element('modal')
    expect(modal.getAttribute('role')).toBe('dialog')
    expect(modal.getAttribute('aria-modal')).toBe('true')
    expect(modal.getAttribute('aria-label')).toBe('Delete rule')
    expect(document.activeElement).toBe(modal)
    expect(
      modal.querySelectorAll('form, textarea, input, select'),
    ).toHaveLength(0)
    expect(modal.textContent).toContain(
      'This permanently removes the rule from First kind.',
    )
    const rule = element('hilos-moderation-rule-delete-rule')
    expect(rule.textContent).toBe(raw)
    expect(rule.title).toBe(raw)
    expect(rule.classList.contains('text-truncate')).toBe(true)
    expect(rule.querySelector('b')).toBeNull()
    expect(rule.closest('[aria-hidden="true"], [inert]')).toBeNull()
    expect(element('hilos-action-error-slot').getAttribute('role')).toBe(
      'alert',
    )
    expect(element('hilos-action-error-idle').getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(
      element('hilos-moderation-rule-delete-notice-slot').getAttribute('role'),
    ).toBe('status')
    expect(
      element('hilos-moderation-rule-delete-notice-idle').getAttribute(
        'aria-hidden',
      ),
    ).toBe('true')
    expect(confirm().type).toBe('button')
    expect(confirm().textContent?.trim()).toBe('Delete')
    expect(confirm().classList.contains('btn-danger')).toBe(true)
    modal.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
    )
    expect(h.requests()).toEqual([])
    h.source.set(kindRow('first', 'Renamed kind'))
    await nextTick()
    expect(modal.textContent).toContain(
      'This permanently removes the rule from First kind.',
    )
  })

  it.each(['cancel', 'escape', 'close', 'backdrop'])(
    'traps keyboard focus and closes by %s without a discard step or dispatch',
    async (close) => {
      const h = harness()
      await h.open()
      element('modal-close').focus()
      element('modal-close').dispatchEvent(
        new KeyboardEvent('keydown', {
          key: 'Tab',
          shiftKey: true,
          bubbles: true,
          cancelable: true,
        }),
      )
      expect(document.activeElement).toBe(confirm())
      confirm().dispatchEvent(
        new KeyboardEvent('keydown', {
          key: 'Tab',
          bubbles: true,
          cancelable: true,
        }),
      )
      expect(document.activeElement).toBe(element('modal-close'))
      if (close === 'cancel')
        element('hilos-moderation-rule-delete-cancel').click()
      if (close === 'escape')
        element('modal').dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      if (close === 'close') element('modal-close').click()
      if (close === 'backdrop') element('modal').click()
      await settled()
      expect(h.remove.openState.get()).toBe(false)
      expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
      expect(h.rules.controller.focusedRow.get()).toBeUndefined()
      expect(document.activeElement).toBe(h.opener)
      expect(h.requests()).toEqual([])
    },
  )

  it('sends the exact snapshot once, locks every close immediately and delays only the spinner', async () => {
    vi.useFakeTimers()
    const h = harness()
    await h.open()
    const button = confirm()
    const rule = element('hilos-moderation-rule-delete-rule')
    button.focus()
    button.click()
    // A forged component event before Vue redraws must use the same synchronous guard.
    h.view
      .getComponent(LoadingButton)
      .vm.$emit('click', new MouseEvent('click'))
    expect(h.remove.saving.get()).toBe(true)
    expect(h.requests()).toEqual([
      {
        type: 'action',
        action: 'hilos_moderation_rule_delete',
        data: { id: 1, kind: 'first', expectedRule: '  Raw text\n' },
        requestId: expect.any(String),
      },
    ])
    await nextTick()
    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(button.getAttribute('aria-disabled')).toBe('true')
    expect(button.disabled).toBe(false)
    expect(document.activeElement).toBe(button)
    expect(
      element<HTMLButtonElement>('hilos-moderation-rule-delete-cancel')
        .disabled,
    ).toBe(true)
    expect(element<HTMLButtonElement>('modal-close').disabled).toBe(true)
    expect(
      document.querySelector('[data-id="loading-button-spinner"]'),
    ).toBeNull()
    button.click()
    element('modal').dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    element('modal').click()
    element('modal-close').click()
    element('hilos-moderation-rule-delete-cancel').click()
    await nextTick()
    expect(h.remove.openState.get()).toBe(true)
    expect(h.requests()).toHaveLength(1)
    expect(element('hilos-moderation-rule-delete-rule')).toBe(rule)
    expect(rule.closest('[aria-hidden="true"], [inert]')).toBeNull()
    await vi.advanceTimersByTimeAsync(DEFAULT_SPINNER_DELAY_MS - 1)
    expect(
      document.querySelector('[data-id="loading-button-spinner"]'),
    ).toBeNull()
    await vi.advanceTimersByTimeAsync(1)
    expect(element('loading-button-spinner')).toBeTruthy()
    h.reply('unrelated')
    await settled()
    expect(h.remove.openState.get()).toBe(true)
    h.reply()
    await settled()
    expect(h.remove.openState.get()).toBe(false)
    expect(h.rules.controller.rows.get()).toHaveLength(1)
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
    expect(document.activeElement).toBe(h.opener)
    expect(
      hilosToasts.toasts
        .get()
        .map(({ severity, message, repeats }) => [severity, message, repeats]),
    ).toEqual([['success', 'Rule removed from "First kind".', 1]])
  })

  it('keeps the refused snapshot and line, opens standard details and starts the next attempt clean', async () => {
    const h = harness()
    await h.open()
    const slot = element('hilos-action-error-slot')
    const rule = element('hilos-moderation-rule-delete-rule')
    confirm().click()
    h.reply(undefined, false)
    await settled()
    expect(h.remove.openState.get()).toBe(true)
    expect(element('hilos-action-error-slot')).toBe(slot)
    expect(element('hilos-moderation-rule-delete-rule')).toBe(rule)
    expect(rule.textContent).toBe('  Raw text\n')
    expect(element('hilos-moderation-rule-delete-error').textContent).toContain(
      'This rule could not be removed.',
    )
    expect(element('hilos-action-error-type').textContent).toBe(
      'DatabaseException',
    )
    expect(confirm().disabled).toBe(false)
    expect(confirm().hasAttribute('aria-busy')).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])
    element('hilos-action-error-details').focus()
    element('hilos-action-error-details').click()
    await settled()
    const details = Array.from(
      document.querySelectorAll('[role="dialog"]'),
    ).find(
      (dialog) =>
        dialog.getAttribute('aria-label') === "Couldn't delete the rule",
    )
    expect(details?.textContent).toContain('The database refused the deletion.')
    expect(document.activeElement).toBe(details)
    element('hilos-action-error-close').click()
    await settled()
    expect(document.activeElement).toBe(element('hilos-action-error-details'))
    element('hilos-moderation-rule-delete-cancel').click()
    await settled()
    await h.open()
    expect(document.querySelector('[data-id="hilos-action-error"]')).toBeNull()
    confirm().click()
    h.reply(undefined, true, null)
    await settled()
    expect(h.remove.openState.get()).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it.each(['conflict', 'deleted'] as const)(
    'reserves the notice line and blocks even a forged click after %s',
    async (change) => {
      const h = harness()
      await h.open()
      const slot = element('hilos-moderation-rule-delete-notice-slot')
      h.rules.controller.ingestDelta(
        change === 'deleted'
          ? { kind: 'row_removed', rowKey: '1', reason: 'deleted' }
          : { kind: 'row_updated', rowKey: '1', row: wireRule('Changed rule') },
      )
      await nextTick()
      expect(element('hilos-moderation-rule-delete-notice-slot')).toBe(slot)
      expect(
        element('hilos-moderation-rule-delete-notice').textContent,
      ).toContain(
        change === 'deleted'
          ? 'The rule no longer exists.'
          : 'The rule changed. Close this window and confirm again.',
      )
      expect(element('hilos-moderation-rule-delete-rule').textContent).toBe(
        '  Raw text\n',
      )
      expect(confirm().disabled).toBe(true)
      confirm().click()
      h.view
        .getComponent(LoadingButton)
        .vm.$emit('click', new MouseEvent('click'))
      await settled()
      expect(h.requests()).toEqual([])
      expect(h.remove.openState.get()).toBe(true)
      element('hilos-moderation-rule-delete-cancel').click()
      await settled()
      expect(h.remove.openState.get()).toBe(false)
    },
  )

  it.each(['viewer', 'takeover'] as const)(
    'lets %s read and Cancel while Delete and a forged click send nothing',
    async (mode) => {
      const h = harness(mode)
      await h.open()
      expect(element('hilos-moderation-rule-delete-rule').textContent).toBe(
        '  Raw text\n',
      )
      expect(confirm().disabled).toBe(true)
      expect(confirm().getAttribute('aria-describedby')).toBe(
        mode === 'viewer'
          ? HILOS_VIEW_MODE_STRIP_TEXT_ID
          : HILOS_IMPERSONATION_STRIP_TEXT_ID,
      )
      confirm().click()
      h.view
        .getComponent(LoadingButton)
        .vm.$emit('click', new MouseEvent('click'))
      await settled()
      expect(h.requests()).toEqual([])
      expect(
        element<HTMLButtonElement>('hilos-moderation-rule-delete-cancel')
          .disabled,
      ).toBe(false)
      element('hilos-moderation-rule-delete-cancel').click()
      await settled()
      expect(h.remove.openState.get()).toBe(false)
    },
  )

  it.each([
    { change: 'kind', success: true },
    { change: 'kind', success: false },
    { change: 'scope', success: true },
    { change: 'scope', success: false },
  ])(
    'isolates an old result ($success) after $change change while the new confirmation saves',
    async ({ change, success }) => {
      const h = harness()
      await h.open()
      confirm().click()
      const oldId = String(h.requests()[0].requestId)
      if (change === 'kind') h.source.set(kindRow('second', 'Second kind'))
      else h.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
      h.window('New snapshot', change === 'kind' ? 'second' : 'first')
      await settled()
      expect(
        document.querySelector('[data-id="hilos-moderation-rule-delete"]'),
      ).toBeNull()
      await h.open()
      expect(confirm().disabled).toBe(false)
      expect(confirm().hasAttribute('aria-busy')).toBe(false)
      confirm().click()
      await nextTick()
      expect(h.requests()).toHaveLength(2)
      h.reply(oldId, success)
      await settled()
      expect(element('hilos-moderation-rule-delete-rule').textContent).toBe(
        'New snapshot',
      )
      expect(confirm().getAttribute('aria-busy')).toBe('true')
      expect(h.remove.saving.get()).toBe(true)
      expect(
        document.querySelector('[data-id="hilos-action-error"]'),
      ).toBeNull()
      h.reply()
      await settled()
      expect(h.remove.openState.get()).toBe(false)
    },
  )
})

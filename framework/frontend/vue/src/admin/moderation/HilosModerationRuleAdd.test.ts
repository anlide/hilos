import { flushPromises, mount } from '@vue/test-utils'
import {
  ActionLifecycle,
  DEFAULT_SPINNER_DELAY_MS,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosConnection,
  HilosPages,
  ScopeManager,
  createSignal,
  hilosToasts,
  type WebSocketLike,
} from '@hilos/core/base'
import {
  createHilosModerationRuleAdd,
  type HilosModerationKindRow,
} from '@hilos/core/admin/moderation'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import {
  hilosAdminViewModeKey,
  hilosTakeoverViewOnlyKey,
} from '../../hilosAdminViewMode.js'
import HilosActionError from '../../HilosActionError.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import HilosModerationRuleAdd from './HilosModerationRuleAdd.vue'

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
    ownRuleCount: 0,
    profile: 'text',
    reasons: [],
    defaultRules: ['Built-in rule'],
  }
}

/** Real lifecycle, tracked runner and modal primitives; only the socket and page rows are scripted. */
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
  const current = createSignal(true)
  const add = createHilosModerationRuleAdd(
    { connection, scopes, actions },
    source,
    current,
  )
  connection.connect()
  listeners.get('open')?.({})
  add.start()
  const opener = document.createElement('button')
  opener.textContent = 'Add rule'
  opener.onclick = () => add.open()
  document.body.append(opener)
  const view = mount(HilosModerationRuleAdd, {
    props: { add },
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
    add.dispose()
    connection.close()
    opener.remove()
  })
  return {
    add,
    source,
    current,
    scopes,
    sent,
    view,
    opener,
    async open() {
      opener.focus()
      opener.click()
      await settled()
    },
    reply(
      requestId = String(sent.at(-1)?.requestId),
      success = true,
      message: string | null = 'Rule added.',
    ) {
      listeners.get('message')?.({
        data: JSON.stringify({
          type: success ? 'action_success' : 'action_error',
          requestId,
          outcome: success ? 'success' : 'fail',
          data: {
            action: 'hilos_moderation_rule_add',
            ...(success
              ? message === null
                ? {}
                : { message }
              : {
                  reason: 'This rule could not be added.',
                  errorType: 'DatabaseException',
                  errorDetail: 'The database refused the rule.',
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

function field(): HTMLTextAreaElement {
  return element('hilos-moderation-rule-add-rule')
}

function save(): HTMLButtonElement {
  return element('hilos-moderation-rule-add-submit')
}

async function input(value: string): Promise<void> {
  field().value = value
  field().dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

function submit(): void {
  field().form!.dispatchEvent(
    new Event('submit', { bubbles: true, cancelable: true }),
  )
}

async function settled(): Promise<void> {
  await flushPromises()
  await nextTick()
}

describe('HilosModerationRuleAdd', () => {
  it('mounts the named modal and one labeled autofocus textarea with a permanent refusal slot', async () => {
    const h = harness()
    await h.open()
    expect(h.view.findComponent(HilosModal).exists()).toBe(true)
    expect(h.view.findComponent(HilosActionError).exists()).toBe(true)
    expect(h.view.findComponent(LoadingButton).exists()).toBe(true)
    const modal = element('modal')
    expect(modal.getAttribute('role')).toBe('dialog')
    expect(modal.getAttribute('aria-modal')).toBe('true')
    expect(modal.getAttribute('aria-label')).toBe('Add rule · First kind')
    expect(modal.querySelectorAll('textarea')).toHaveLength(1)
    expect(modal.querySelectorAll('input, select')).toHaveLength(0)
    expect(
      modal.querySelector(`label[for="${field().id}"]`)?.textContent?.trim(),
    ).toBe('Rule')
    expect(field().getAttribute('rows')).toBe('4')
    expect(document.activeElement).toBe(field())
    element('modal-close').focus()
    element('modal-close').dispatchEvent(
      new KeyboardEvent('keydown', {
        key: 'Tab',
        shiftKey: true,
        bubbles: true,
        cancelable: true,
      }),
    )
    expect(document.activeElement).toBe(
      element('hilos-moderation-rule-add-cancel'),
    )
    element('hilos-moderation-rule-add-cancel').dispatchEvent(
      new KeyboardEvent('keydown', {
        key: 'Tab',
        bubbles: true,
        cancelable: true,
      }),
    )
    expect(document.activeElement).toBe(element('modal-close'))
    expect(save().textContent?.trim()).toBe('Save')
    expect(save().type).toBe('submit')
    expect(save().form).toBe(field().form)
    expect(element('hilos-action-error-slot').getAttribute('role')).toBe(
      'alert',
    )
    expect(element('hilos-action-error-slot').getAttribute('aria-live')).toBe(
      'assertive',
    )
    expect(element('hilos-action-error-idle').getAttribute('aria-hidden')).toBe(
      'true',
    )
    h.source.set(kindRow('first', 'Changed title'))
    await nextTick()
    expect(modal.getAttribute('aria-label')).toBe('Add rule · First kind')
  })

  it.each(['', ' ', '\n\t  '])(
    'guards Save and forged submit for empty text %j',
    async (value) => {
      const h = harness()
      await h.open()
      await input(value)
      expect(save().disabled).toBe(true)
      save().click()
      submit()
      await settled()
      expect(h.sent).toEqual([])
      expect(h.add.openState.get()).toBe(true)
      expect(field().value).toBe(value)
    },
  )

  it('leaves Enter to the textarea and dispatches the captured kind and raw multiline draft once', async () => {
    const h = harness()
    await h.open()
    await input('  Reject phones.')
    const enter = new KeyboardEvent('keydown', {
      key: 'Enter',
      bubbles: true,
      cancelable: true,
    })
    field().dispatchEvent(enter)
    expect(enter.defaultPrevented).toBe(false)
    expect(h.sent).toEqual([])
    // The DOM emulator does not insert text for a key's native default action.
    const raw = '  Reject phones.\n\n  Keep punctuation: +48.\n'
    await input(raw)
    save().click()
    submit()
    expect(h.sent).toEqual([
      {
        type: 'action',
        action: 'hilos_moderation_rule_add',
        data: { kind: 'first', rule: raw },
        requestId: expect.any(String),
      },
    ])
    expect(h.add.openState.get()).toBe(true)
    h.reply('unrelated-request')
    await settled()
    expect(h.add.openState.get()).toBe(true)
    h.reply()
    await settled()
    expect(
      document.querySelector('[data-id="hilos-moderation-rule-add"]'),
    ).toBeNull()
    expect(
      hilosToasts.toasts
        .get()
        .map(({ severity, message }) => [severity, message]),
    ).toEqual([['success', 'Rule added.']])
    expect(h.sent).toHaveLength(1)
    expect(document.activeElement).toBe(h.opener)
  })

  it('locks body, repeat submit and every close immediately, with one spinner delay and no field remount', async () => {
    vi.useFakeTimers()
    const h = harness()
    await h.open()
    await input('Original draft')
    const textarea = field()
    const button = save()
    button.focus()
    button.click()
    await nextTick()
    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(button.getAttribute('aria-disabled')).toBe('true')
    expect(button.disabled).toBe(false)
    expect(document.activeElement).toBe(button)
    expect(textarea.closest('fieldset')?.disabled).toBe(true)
    expect(
      element<HTMLButtonElement>('hilos-moderation-rule-add-cancel').disabled,
    ).toBe(true)
    expect(element<HTMLButtonElement>('modal-close').disabled).toBe(true)
    expect(
      document.querySelector('[data-id="loading-button-spinner"]'),
    ).toBeNull()
    button.click()
    submit()
    textarea.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    element('modal').click()
    element('modal-close').click()
    element('hilos-moderation-rule-add-cancel').click()
    h.add.setRule('Changed')
    await nextTick()
    expect(h.sent).toHaveLength(1)
    expect(h.add.rule.get()).toBe('Original draft')
    expect(field()).toBe(textarea)
    expect(textarea.value).toBe('Original draft')
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    await vi.advanceTimersByTimeAsync(DEFAULT_SPINNER_DELAY_MS - 1)
    expect(
      document.querySelector('[data-id="loading-button-spinner"]'),
    ).toBeNull()
    await vi.advanceTimersByTimeAsync(1)
    expect(element('loading-button-spinner')).toBeTruthy()
    expect(field()).toBe(textarea)
    h.reply(undefined, false)
    await settled()
    expect(field()).toBe(textarea)
    expect(textarea.matches(':disabled')).toBe(false)
    expect(button.hasAttribute('aria-busy')).toBe(false)
    expect(
      document.querySelector('[data-id="loading-button-spinner"]'),
    ).toBeNull()
  })

  it('keeps the refused draft and permanent line, exposes standard details, and never toasts the refusal', async () => {
    const h = harness()
    await h.open()
    const slot = element('hilos-action-error-slot')
    const textarea = field()
    await input('  Draft\n')
    submit()
    h.reply(undefined, false)
    await settled()
    expect(field()).toBe(textarea)
    expect(field().value).toBe('  Draft\n')
    expect(element('hilos-action-error-slot')).toBe(slot)
    expect(element('hilos-moderation-rule-add-error').textContent).toContain(
      'This rule could not be added.',
    )
    expect(element('hilos-action-error-type').textContent).toBe(
      'DatabaseException',
    )
    expect(save().disabled).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])
    element('hilos-action-error-details').focus()
    element('hilos-action-error-details').click()
    await settled()
    const details = Array.from(
      document.querySelectorAll('[role="dialog"]'),
    ).find(
      (dialog) => dialog.getAttribute('aria-label') === "Couldn't add the rule",
    )
    expect(details).toBeDefined()
    expect(details?.textContent).toContain('The database refused the rule.')
    expect(document.activeElement).toBe(details)
    element('hilos-action-error-close').click()
    await settled()
    expect(field()).toBe(textarea)
    expect(document.activeElement).toBe(element('hilos-action-error-details'))
    submit()
    h.reply()
    await settled()
    expect(h.sent).toHaveLength(2)
    expect(h.add.openState.get()).toBe(false)
    expect(hilosToasts.toasts.get()).toHaveLength(1)
  })

  it('stays silent on a successful reply without a message and starts the next opening clean', async () => {
    const h = harness()
    await h.open()
    await input('Draft')
    submit()
    h.reply(undefined, true, null)
    await settled()
    expect(h.add.openState.get()).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])
    await h.open()
    expect(field().value).toBe('')
    expect(save().disabled).toBe(true)
    expect(document.querySelector('[data-id="hilos-action-error"]')).toBeNull()
  })

  it('closes empty Cancel immediately and traps dirty Cancel/Escape in the standard discard layer', async () => {
    const h = harness()
    await h.open()
    element('hilos-moderation-rule-add-cancel').click()
    await settled()
    expect(h.add.openState.get()).toBe(false)
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    expect(document.activeElement).toBe(h.opener)
    await h.open()
    await input('Draft')
    element('hilos-moderation-rule-add-cancel').click()
    await settled()
    const confirm = element('modal-confirm')
    expect(confirm.getAttribute('role')).toBe('alertdialog')
    expect(confirm.getAttribute('aria-label')).toBe('Discard changes?')
    expect(document.activeElement).toBe(confirm)
    element('modal-confirm-cancel').click()
    await settled()
    expect(document.activeElement).toBe(field())
    expect(field().value).toBe('Draft')
    field().dispatchEvent(
      new KeyboardEvent('keydown', {
        key: 'Escape',
        bubbles: true,
        cancelable: true,
      }),
    )
    await settled()
    expect(element('modal-confirm')).toBeTruthy()
    element('modal-confirm-discard').click()
    await settled()
    expect(h.add.openState.get()).toBe(false)
    expect(document.activeElement).toBe(h.opener)
    expect(h.sent).toEqual([])
  })

  it.each(['viewer', 'takeover'] as const)(
    'lets %s open, type, select for copy and Cancel, while Save and forged submit send nothing',
    async (mode) => {
      const h = harness(mode)
      await h.open()
      await input('Copy this\ndraft')
      expect(field().matches(':disabled')).toBe(false)
      expect(field().readOnly).toBe(false)
      field().select()
      expect(
        field().value.slice(field().selectionStart, field().selectionEnd),
      ).toBe('Copy this\ndraft')
      expect(
        field().dispatchEvent(
          new Event('copy', { bubbles: true, cancelable: true }),
        ),
      ).toBe(true)
      expect(save().disabled).toBe(true)
      expect(save().getAttribute('aria-describedby')).toBe(
        mode === 'viewer'
          ? HILOS_VIEW_MODE_STRIP_TEXT_ID
          : HILOS_IMPERSONATION_STRIP_TEXT_ID,
      )
      save().click()
      submit()
      await settled()
      expect(h.sent).toEqual([])
      expect(
        element<HTMLButtonElement>('hilos-moderation-rule-add-cancel').disabled,
      ).toBe(false)
      element('hilos-moderation-rule-add-cancel').click()
      await settled()
      element('modal-confirm-discard').click()
      await settled()
      expect(h.add.openState.get()).toBe(false)
    },
  )

  it.each([
    { change: 'kind', success: true },
    { change: 'kind', success: false },
    { change: 'scope', success: true },
    { change: 'scope', success: false },
  ])(
    'isolates an old result ($success) after $change change, even while the new form is saving',
    async ({ change, success }) => {
      const h = harness()
      await h.open()
      await input('Old draft')
      submit()
      const oldId = String(h.sent[0].requestId)
      if (change === 'kind') h.source.set(kindRow('second', 'Second kind'))
      else h.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
      await settled()
      expect(
        document.querySelector('[data-id="hilos-moderation-rule-add"]'),
      ).toBeNull()
      await h.open()
      await input('New draft')
      expect(save().hasAttribute('aria-busy')).toBe(false)
      expect(save().disabled).toBe(false)
      submit()
      await nextTick()
      expect(h.sent).toHaveLength(2)
      expect(h.sent[1].data).toEqual({
        kind: change === 'kind' ? 'second' : 'first',
        rule: 'New draft',
      })
      const textarea = field()
      h.reply(oldId, success)
      await settled()
      expect(field()).toBe(textarea)
      expect(field().value).toBe('New draft')
      expect(save().getAttribute('aria-busy')).toBe('true')
      expect(h.add.saving.get()).toBe(true)
      expect(
        document.querySelector('[data-id="hilos-action-error"]'),
      ).toBeNull()
      expect(element('modal').getAttribute('aria-label')).toBe(
        change === 'kind' ? 'Add rule · Second kind' : 'Add rule · First kind',
      )
      h.reply()
      await settled()
      expect(h.add.openState.get()).toBe(false)
    },
  )
})

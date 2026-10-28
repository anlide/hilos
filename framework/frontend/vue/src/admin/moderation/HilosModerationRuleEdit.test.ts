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
  createHilosModerationRuleEdit,
  createHilosModerationRules,
  type HilosModerationKindRow,
} from '@hilos/core/admin/moderation'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import {
  hilosAdminViewModeKey,
  hilosTakeoverViewOnlyKey,
} from '../../hilosAdminViewMode.js'
import HilosModerationRuleEdit from './HilosModerationRuleEdit.vue'

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

/** Real factory, table focus, action lifecycle and view primitives; only the socket and rows are scripted. */
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
  const actions = new ActionLifecycle(connection, { timeoutMs: 1000 })
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MODERATION_KIND_RULES)
  const kind = createSignal<HilosModerationKindRow | null>(kindRow())
  const context = { connection, scopes, actions }
  const rules = createHilosModerationRules(
    context,
    computedSignal(() => kind.get()?.kind ?? ''),
  )
  const edit = createHilosModerationRuleEdit(context, kind, rules)
  function wireRule(rule = 'Saved rule') {
    return {
      rowKey: '1',
      slots: { rule: { id: 1, kind: kind.get()!.kind, rule } },
    }
  }
  function window(rule = 'Saved rule') {
    rules.controller.ingestWindow([wireRule(rule)], 1, true, null, null, 25, 0)
  }
  connection.connect()
  listeners.get('open')?.({})
  rules.start()
  window()
  edit.start()
  const opener = document.createElement('button')
  opener.textContent = 'Edit rule'
  opener.onclick = () => edit.open('1')
  document.body.append(opener)
  const view = mount(HilosModerationRuleEdit, {
    props: { edit },
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
    edit.dispose()
    rules.dispose()
    connection.close()
    opener.remove()
  })
  const writes = () => sent.filter((frame) => frame.type === 'action')
  return {
    edit,
    kind,
    scopes,
    rules,
    sent,
    writes,
    view,
    opener,
    window,
    incoming(rule: string) {
      rules.controller.ingestDelta({
        kind: 'row_updated',
        rowKey: '1',
        row: wireRule(rule),
      })
    },
    gone() {
      rules.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: '1',
        reason: 'deleted',
      })
    },
    async open() {
      opener.focus()
      opener.click()
      await settled()
    },
    reply(
      requestId = String(writes().at(-1)?.requestId),
      success = true,
      message: string | null = 'Rule updated for "First kind".',
      reason = 'The rule changed. Review it and try again.',
    ) {
      listeners.get('message')?.({
        data: JSON.stringify({
          type: success ? 'action_success' : 'action_error',
          requestId,
          outcome: success ? 'success' : 'fail',
          data: {
            action: 'hilos_moderation_rule_update',
            ...(success
              ? message === null
                ? {}
                : { message }
              : {
                  reason,
                  errorType: 'ValidationException',
                  errorDetail: 'The saved rule no longer matches.',
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
  return element('hilos-moderation-rule-edit-rule')
}

function save(): HTMLButtonElement {
  return element('hilos-moderation-rule-edit-submit')
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

describe('HilosModerationRuleEdit', () => {
  it('binds the named modal, labeled textarea and permanent refusal, notice and choice rooms', async () => {
    const h = harness()
    await h.open()
    for (const component of [
      HilosModal,
      HilosActionError,
      HilosEditNotice,
      ConflictActions,
      LoadingButton,
    ]) {
      expect(h.view.findComponent(component).exists()).toBe(true)
    }
    const modal = element('modal')
    expect(modal.getAttribute('role')).toBe('dialog')
    expect(modal.getAttribute('aria-label')).toBe('Edit rule · First kind')
    expect(modal.querySelectorAll('textarea')).toHaveLength(1)
    expect(modal.querySelectorAll('input, select')).toHaveLength(0)
    expect(
      modal.querySelector(`label[for="${field().id}"]`)?.textContent?.trim(),
    ).toBe('Rule')
    expect(field().getAttribute('rows')).toBe('4')
    expect(field().value).toBe('Saved rule')
    expect(field().form!.id).toBe('hilos-moderation-rule-edit-form')
    expect(document.activeElement).toBe(field())
    const body = element('hilos-moderation-rule-edit')
    expect(Array.from(body.children).map((child) => child.tagName)).toEqual([
      'DIV',
      'FORM',
    ])
    expect(body.firstElementChild?.getAttribute('data-id')).toBe(
      'hilos-moderation-rule-edit-error',
    )
    expect(field().nextElementSibling).toBe(
      element('hilos-moderation-rule-edit-notice-slot'),
    )
    for (const id of [
      'hilos-action-error-idle',
      'hilos-moderation-rule-edit-notice-idle',
      'conflict-choices-idle',
    ]) {
      expect(element(id).classList.contains('invisible')).toBe(true)
      expect(element(id).getAttribute('aria-hidden')).toBe('true')
    }
    expect(
      element('hilos-moderation-rule-edit-notice-slot').getAttribute('role'),
    ).toBe('status')
    h.kind.set(kindRow('first', 'Renamed kind'))
    await nextTick()
    expect(modal.getAttribute('aria-label')).toBe('Edit rule · First kind')
  })

  it.each(['Saved rule', '', ' \n\t'])(
    'guards Save and form submit for %j',
    async (value) => {
      const h = harness()
      await h.open()
      await input(value)
      expect(save().disabled).toBe(true)
      save().click()
      h.view
        .getComponent(LoadingButton)
        .vm.$emit('click', new MouseEvent('click'))
      submit()
      await settled()
      expect(h.writes()).toEqual([])
      expect(h.edit.opened.get()).toBe(true)
      expect(field().value).toBe(value)
    },
  )

  it('draws live conflicts, wires both choices, and saves raw text against the accepted incoming value', async () => {
    const h = harness()
    await h.open()
    const slot = element('hilos-moderation-rule-edit-notice-slot')
    h.incoming('Accepted elsewhere')
    await nextTick()
    expect(field().value).toBe('Accepted elsewhere')
    expect(element('hilos-moderation-rule-edit-notice').textContent).toContain(
      'Updated just now',
    )
    await input('  My raw\n\nrule\n')
    h.incoming('Incoming one')
    await nextTick()
    expect(element('hilos-moderation-rule-edit-notice').textContent).toContain(
      'Rule changed elsewhere to "Incoming one".',
    )
    expect(save().disabled).toBe(true)
    submit()
    expect(h.writes()).toEqual([])
    expect(document.querySelector('[data-id="conflict-merge"]')).toBeNull()
    element('conflict-accept-theirs').click()
    await nextTick()
    expect(field().value).toBe('Incoming one')
    expect(save().disabled).toBe(true)
    const raw = '  My raw\n\nrule\n'
    await input(raw)
    h.incoming('Incoming two')
    await nextTick()
    element('conflict-accept-mine').click()
    await nextTick()
    expect(field().value).toBe(raw)
    expect(save().disabled).toBe(false)
    expect(element('hilos-moderation-rule-edit-notice-slot')).toBe(slot)
    field().dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
    )
    expect(h.writes()).toEqual([])
    save().click()
    expect(h.writes()).toEqual([
      {
        type: 'action',
        action: 'hilos_moderation_rule_update',
        data: { id: 1, kind: 'first', rule: raw, expectedRule: 'Incoming two' },
        requestId: expect.any(String),
      },
    ])
    h.reply('unrelated')
    await settled()
    expect(h.edit.opened.get()).toBe(true)
    h.reply()
    await settled()
    expect(h.edit.opened.get()).toBe(false)
    expect(h.rules.controller.focusedRow.get()).toBeUndefined()
    expect(
      hilosToasts.toasts
        .get()
        .map(({ message, severity, repeats }) => [message, severity, repeats]),
    ).toEqual([['Rule updated for "First kind".', 'success', 1]])
    expect(document.activeElement).toBe(h.opener)
  })

  it('locks the textarea, repeat submits and every close immediately without remounting or flashing a spinner', async () => {
    vi.useFakeTimers()
    const h = harness()
    await h.open()
    await input('Pending draft')
    const textarea = field()
    const button = save()
    button.focus()
    button.click()
    await nextTick()
    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(button.disabled).toBe(false)
    expect(document.activeElement).toBe(button)
    expect(textarea.disabled).toBe(true)
    expect(
      element<HTMLButtonElement>('hilos-moderation-rule-edit-cancel').disabled,
    ).toBe(true)
    expect(element<HTMLButtonElement>('modal-close').disabled).toBe(true)
    expect(
      document.querySelector('[data-id="loading-button-spinner"]'),
    ).toBeNull()
    button.click()
    submit()
    element('hilos-moderation-rule-edit-cancel').click()
    element('modal-close').click()
    element('modal').click()
    textarea.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await nextTick()
    expect(h.writes()).toHaveLength(1)
    expect(h.edit.opened.get()).toBe(true)
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    await vi.advanceTimersByTimeAsync(DEFAULT_SPINNER_DELAY_MS)
    expect(element('loading-button-spinner')).toBeTruthy()
    expect(field()).toBe(textarea)
    h.reply()
    await settled()
    expect(h.edit.opened.get()).toBe(false)
  })

  it('keeps refusal and draft inside the window, exposes details and retries only on a new submit', async () => {
    const h = harness()
    await h.open()
    const textarea = field()
    const slot = element('hilos-action-error-slot')
    await input('Refused draft')
    submit()
    h.reply(undefined, false)
    await settled()
    expect(field()).toBe(textarea)
    expect(field().value).toBe('Refused draft')
    expect(field().disabled).toBe(false)
    expect(element('hilos-action-error-slot')).toBe(slot)
    expect(element('hilos-moderation-rule-edit-error').textContent).toContain(
      'The rule changed. Review it and try again.',
    )
    expect(hilosToasts.toasts.get()).toEqual([])
    expect(h.writes()).toHaveLength(1)
    element('hilos-action-error-details').click()
    await settled()
    const details = Array.from(
      document.querySelectorAll('[role="dialog"]'),
    ).find(
      (dialog) =>
        dialog.getAttribute('aria-label') === "Couldn't save the rule",
    )
    expect(details).toBeDefined()
    expect(details?.textContent).toContain('The saved rule no longer matches.')
    element('hilos-action-error-close').click()
    await settled()
    expect(field()).toBe(textarea)
    submit()
    h.reply()
    await settled()
    expect(h.writes()).toHaveLength(2)
    expect(h.edit.opened.get()).toBe(false)
    expect(hilosToasts.toasts.get()).toHaveLength(1)
  })

  it('keeps a timeout inline without retry and starts the next opening without the old error or draft', async () => {
    vi.useFakeTimers()
    const h = harness()
    await h.open()
    await input('Timed out draft')
    submit()
    const oldId = String(h.writes()[0].requestId)
    await vi.advanceTimersByTimeAsync(1000)
    await settled()
    expect(element('hilos-moderation-rule-edit-error').textContent).toContain(
      'The action timed out. Please try again.',
    )
    expect(field().value).toBe('Timed out draft')
    expect(save().disabled).toBe(false)
    expect(h.writes()).toHaveLength(1)
    expect(hilosToasts.toasts.get()).toEqual([])
    element('hilos-moderation-rule-edit-cancel').click()
    await settled()
    element('modal-confirm-discard').click()
    await settled()
    await h.open()
    expect(field().value).toBe('Saved rule')
    expect(document.querySelector('[data-id="hilos-action-error"]')).toBeNull()
    h.reply(oldId)
    await settled()
    expect(h.edit.opened.get()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
    await input('New draft')
    submit()
    h.reply(undefined, true, null)
    await settled()
    expect(h.edit.opened.get()).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it.each([false, true])(
    'uses the standard discard layer and retains dirty draft and focus when gone=%s',
    async (gone) => {
      const h = harness()
      await h.open()
      await input('Draft to copy')
      const textarea = field()
      if (gone) {
        h.gone()
        await nextTick()
        expect(save().textContent?.trim()).toBe('Deleted')
        expect(save().disabled).toBe(true)
        expect(
          element('hilos-moderation-rule-edit-notice').textContent,
        ).toContain('Deleted elsewhere — your text stays to copy.')
        submit()
        expect(h.writes()).toEqual([])
      }
      element('hilos-moderation-rule-edit-cancel').click()
      await settled()
      expect(element('modal-confirm').getAttribute('aria-label')).toBe(
        'Discard changes?',
      )
      expect(document.activeElement).toBe(element('modal-confirm'))
      element('modal-confirm-cancel').click()
      await settled()
      expect(field()).toBe(textarea)
      expect(field().value).toBe('Draft to copy')
      expect(document.activeElement).toBe(textarea)
      if (!gone) expect(h.rules.controller.focusedRow.get()?.id).toBe(1)
      textarea.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
      await settled()
      element('modal-confirm-discard').click()
      await settled()
      expect(h.edit.opened.get()).toBe(false)
      expect(h.edit.target.get()).toBeNull()
      expect(
        document.querySelector('[data-id="hilos-moderation-rule-edit"]'),
      ).toBeNull()
      expect(h.rules.controller.focusedRow.get()).toBeUndefined()
      expect(document.activeElement).toBe(h.opener)
      expect(h.writes()).toEqual([])
      h.window('Fresh saved rule')
      await h.open()
      expect(field().value).toBe('Fresh saved rule')
      expect(h.edit.dirty.get()).toBe(false)
    },
  )

  it('closes an unchanged deleted rule without a discard question', async () => {
    const h = harness()
    await h.open()
    h.gone()
    await nextTick()
    expect(field().value).toBe('Saved rule')
    expect(save().textContent?.trim()).toBe('Deleted')
    element('hilos-moderation-rule-edit-cancel').click()
    await settled()
    expect(h.edit.opened.get()).toBe(false)
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
  })

  it.each(['viewer', 'takeover'] as const)(
    'lets %s draft, choose conflicts and cancel while guarding Save and forged submit/click',
    async (mode) => {
      const h = harness(mode)
      await h.open()
      await input('Local draft')
      h.incoming('Other draft')
      await nextTick()
      element('conflict-accept-theirs').click()
      await nextTick()
      expect(field().value).toBe('Other draft')
      await input('Copy this draft')
      h.incoming('Another incoming')
      await nextTick()
      element('conflict-accept-mine').click()
      await nextTick()
      expect(field().disabled).toBe(false)
      expect(field().readOnly).toBe(false)
      field().select()
      expect(
        field().value.slice(field().selectionStart, field().selectionEnd),
      ).toBe('Copy this draft')
      expect(save().disabled).toBe(true)
      expect(save().getAttribute('aria-describedby')).toBe(
        mode === 'viewer'
          ? HILOS_VIEW_MODE_STRIP_TEXT_ID
          : HILOS_IMPERSONATION_STRIP_TEXT_ID,
      )
      save().click()
      h.view
        .getComponent(LoadingButton)
        .vm.$emit('click', new MouseEvent('click'))
      h.view.getComponent(ConflictActions).vm.$emit('save')
      submit()
      await settled()
      expect(h.writes()).toEqual([])
      expect(
        element<HTMLButtonElement>('hilos-moderation-rule-edit-cancel')
          .disabled,
      ).toBe(false)
      element('hilos-moderation-rule-edit-cancel').click()
      await settled()
      element('modal-confirm-discard').click()
      await settled()
      expect(h.edit.opened.get()).toBe(false)
    },
  )

  it.each([
    { change: 'scope', success: true },
    { change: 'scope', success: false },
    { change: 'kind', success: true },
    { change: 'kind', success: false },
  ])(
    'isolates late old result ($success) after $change while the replacement is saving',
    async ({ change, success }) => {
      const h = harness()
      await h.open()
      await input('Old draft')
      submit()
      const oldId = String(h.writes()[0].requestId)
      if (change === 'scope')
        h.scopes.openPage(HilosPages.MODERATION_KIND_RULES)
      else h.kind.set(kindRow('second', 'Second kind'))
      h.window()
      await settled()
      expect(
        document.querySelector('[data-id="hilos-moderation-rule-edit"]'),
      ).toBeNull()
      await h.open()
      await input('Replacement draft')
      expect(save().hasAttribute('aria-busy')).toBe(false)
      submit()
      await nextTick()
      const textarea = field()
      h.reply(oldId, success)
      await settled()
      expect(field()).toBe(textarea)
      expect(field().value).toBe('Replacement draft')
      expect(save().getAttribute('aria-busy')).toBe('true')
      expect(h.edit.saving.get()).toBe(true)
      expect(
        document.querySelector('[data-id="hilos-action-error"]'),
      ).toBeNull()
      expect(hilosToasts.toasts.get()).toEqual([])
      h.reply(undefined, false, null, 'Replacement refused.')
      await settled()
      expect(element('hilos-moderation-rule-edit-error').textContent).toContain(
        'Replacement refused.',
      )
      expect(h.edit.opened.get()).toBe(true)
      expect(field().value).toBe('Replacement draft')
    },
  )
})

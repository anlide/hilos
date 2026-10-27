// @vitest-environment jsdom
// Native disabled-fieldset inheritance is part of the interaction/focus checks.
// Covers what the name window's view owns (HIL-1169): the confirmation first
// when the operation asks and the field focused once it gives way to the form,
// Save held while the draft equals the live name, the refusal in the form, the
// notice when the name moved elsewhere, editable background name checks and
// the independent confirmation and connection locks.
import {
  ActionError,
  createConnectionInteractionState,
  createHilosProfileRenameFlow,
  createSignal,
  type ActionLifecycle,
  type HilosProfileRename,
} from '@hilos/core/base'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import {
  profileNameStatus,
  profileRenameTab,
} from '../../test/profileRenameHarness.js'
import { hilosConnectionInteractionKey } from '../hilosConnectionInteraction.js'
import HilosProfileRenameModal from './HilosProfileRename.vue'

const cleanup: Array<() => void> = []
beforeEach(() => sessionStorage.clear())
// The modal teleports to <body>, so assertions query the document.
afterEach(() => {
  for (const stop of cleanup.splice(0).reverse()) stop()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})
enableAutoUnmount(afterEach)

function byId(id: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!found) throw new Error(`Missing ${id}`)
  return found
}

function typeInto(id: string, value: string): void {
  const field = byId(id) as HTMLInputElement
  field.value = value
  field.dispatchEvent(new Event('input'))
}

// The fieldset the modal stands its body in; a request in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

/**
 * Every way a person closes the window: the ×, Cancel, Escape and the backdrop.
 *
 * @param cancel The data-id of the Cancel on screen.
 */
function tryClosing(cancel: string): void {
  byId('modal-close').click()
  byId(cancel).click()
  byId('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  byId('modal').click()
}

/**
 * A mounted window over the project's rename; the rename lands when the test
 * moves the live name or sets the refusal.
 *
 * @param stepUp What the operation's confirmation answers on open.
 * @param hold Actions whose reply stays in flight until the test settles or refuses it.
 */
function setup(
  stepUp: unknown = { required: false, purpose: 'rename' },
  hold: string[] = [],
) {
  const name = createSignal('Ann')
  const refusal = createSignal<string | null>(null)
  const sent: string[] = []
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const held: Array<{ settle(): void; refuse(reason: string): void }> = []
  const rename: HilosProfileRename = {
    send(next) {
      sent.push(next)
      return true
    },
    refusal,
    clearRefusal: () => refusal.set(null),
    stepUpOperation: 'change_name',
    minLength: 2,
    maxLength: 64,
  }
  const flow = createHilosProfileRenameFlow(
    {
      actions: {
        dispatch: (action: string, payload?: unknown) => {
          dispatched.push({ action, payload })
          if (hold.includes(action))
            return {
              done: new Promise((resolve, reject) =>
                held.push({
                  settle: () => resolve({ reply: [] }),
                  refuse: (reason) =>
                    reject(new ActionError(action, 'fail', reason)),
                }),
              ),
            }
          return {
            done:
              action === 'hilos_step_up_start'
                ? Promise.resolve({ reply: stepUp })
                : action === 'hilos_step_up_confirm'
                  ? Promise.resolve({ reply: [] })
                  : Promise.reject(new ActionError(action, 'fail', 'no')),
          }
        },
      } as unknown as ActionLifecycle,
    },
    name,
    rename,
  )
  cleanup.push(flow.dispose)
  mount(HilosProfileRenameModal, { props: { flow }, attachTo: document.body })
  return { flow, name, refusal, sent, dispatched, held }
}

describe('HilosProfileRename', () => {
  it('opens on the live name with Save held until the draft changes', async () => {
    const { flow, sent } = setup()
    await flow.open()
    await flushPromises()

    const dialog = byId('modal')
    expect(dialog.getAttribute('aria-label')).toBe('Change name')
    expect(dialog.getAttribute('aria-label')).toBe(
      dialog.querySelector('.modal-title')?.textContent?.trim(),
    )
    expect((byId('profile-name-input') as HTMLInputElement).value).toBe('Ann')
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(true)
    typeInto('profile-name-input', 'Bob')
    await nextTick()
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(false)
    byId('profile-rename-save').click()
    await nextTick()
    expect(sent).toEqual(['Bob'])
    expect(byId('profile-rename-cancel').hasAttribute('disabled')).toBe(false)
  })

  it('asks the confirmation first and focuses the field once it gives way', async () => {
    const { flow, dispatched } = setup({
      required: true,
      purpose: 'change your name',
      method: 'password',
    })
    await flow.open()
    await flushPromises()
    const dialog = byId('modal')
    expect(dialog.getAttribute('aria-label')).toBe("Confirm it's you")
    expect(dialog.getAttribute('aria-label')).toBe(
      dialog.querySelector('.modal-title')?.textContent?.trim(),
    )
    expect(document.querySelector('.modal-title')?.textContent?.trim()).toBe(
      "Confirm it's you",
    )
    expect(document.querySelector('[data-id="profile-name-input"]')).toBeNull()

    const form = byId('profile-name-step-up')
    const confirm = byId('profile-name-step-up-confirm')
    expect(confirm.getAttribute('type')).toBe('submit')
    expect(confirm.getAttribute('form')).toBe(form.id)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()
    expect(dispatched.some((d) => d.action === 'hilos_step_up_confirm')).toBe(
      false,
    )

    typeInto('step-up-password', 'secret')
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()
    await nextTick()
    expect(dialog.getAttribute('aria-label')).toBe('Change name')
    expect(dialog.getAttribute('aria-label')).toBe(
      dialog.querySelector('.modal-title')?.textContent?.trim(),
    )
    expect(document.activeElement).toBe(byId('profile-name-input'))
    const call = dispatched.find((d) => d.action === 'hilos_step_up_confirm')
    expect(call).toBeDefined()
    expect(call?.payload).toMatchObject({
      password: 'secret',
    })
  })

  it("shows the project's refusal in the form and says when the name moved elsewhere", async () => {
    const { flow, name, refusal } = setup()
    await flow.open()
    await flushPromises()
    refusal.set('That name is not allowed')
    await nextTick()
    expect(byId('profile-rename-error').textContent).toContain(
      'That name is not allowed',
    )

    name.set('Anna')
    await nextTick()
    expect(byId('profile-edit-notice').textContent).toContain(
      'Updated just now',
    )
    expect((byId('profile-name-input') as HTMLInputElement).value).toBe('Anna')
  })

  it('keeps the legacy rename editable with repeat Save and Enter held until refusal', async () => {
    const { flow, name, refusal, sent } = setup()
    await flow.open()
    await flushPromises()
    typeInto('profile-name-input', 'Bob')
    await nextTick()
    const field = byId('profile-name-input') as HTMLInputElement
    const body = bodyLock()
    const cancel = byId('profile-rename-cancel') as HTMLButtonElement
    const close = byId('modal-close') as HTMLButtonElement
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)

    byId('profile-rename-save').click()
    await nextTick()
    expect(sent).toEqual(['Bob'])
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(true)
    byId('profile-rename-save').click()
    field.form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()
    expect(sent).toEqual(['Bob'])

    refusal.set('That name is not allowed')
    await nextTick()
    expect(byId('profile-name-input')).toBe(field)
    expect(field.value).toBe('Bob')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)
    expect(byId('profile-rename-error').textContent).toContain(
      'That name is not allowed',
    )

    byId('profile-rename-save').click()
    await nextTick()
    expect(sent).toEqual(['Bob', 'Bob'])
    name.set('Bob')
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('holds the window and locks the password while the confirmation is out, and frees it after a refusal', async () => {
    const { flow, held } = setup(
      { required: true, purpose: 'change your name', method: 'password' },
      ['hilos_step_up_confirm'],
    )
    await flow.open()
    await flushPromises()
    typeInto('step-up-password', 'wrong')
    await nextTick()
    const form = byId('profile-name-step-up')
    const field = byId('step-up-password') as HTMLInputElement
    const body = bodyLock()
    const cancel = byId('profile-name-step-up-cancel') as HTMLButtonElement
    const close = byId('modal-close') as HTMLButtonElement
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()
    expect(held).toHaveLength(1)
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing('profile-name-step-up-cancel')
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    held[0]!.refuse('That password is not right')
    await flushPromises()
    expect(byId('step-up-password')).toBe(field)
    expect(field.value).toBe('wrong')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)
    expect(byId('step-up-error').textContent).toContain(
      'That password is not right',
    )

    typeInto('step-up-password', 'secret')
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()
    held[1]!.settle()
    await flushPromises()
    expect((byId('profile-name-input') as HTMLInputElement).value).toBe('Ann')
  })
})

function standardWindow() {
  const tab = profileRenameTab()
  const flow = createHilosProfileRenameFlow(
    tab.context,
    tab.binding.name,
    tab.binding.rename,
  )
  const interaction = createConnectionInteractionState(tab.context.connection)
  cleanup.push(tab.dispose, interaction.dispose, flow.dispose)
  flow.start()
  mount(HilosProfileRenameModal, {
    props: { flow },
    attachTo: document.body,
    global: {
      provide: { [hilosConnectionInteractionKey as symbol]: interaction },
    },
  })
  return { ...tab, flow }
}

async function sendStandard() {
  const tab = standardWindow()
  await tab.flow.open()
  await flushPromises()
  typeInto('profile-name-input', 'Bob')
  await nextTick()
  byId('profile-rename-save').click()
  await nextTick()
  return tab
}

describe('standard name state in the real window', () => {
  it('keeps one polite status and its exact twin through pre-ack, checking and applying', async () => {
    const tab = await sendStandard()
    const field = byId('profile-name-input') as HTMLInputElement
    const status = profileNameStatus()
    const twin = status.previousElementSibling!
    expect(status.getAttribute('role')).toBe('status')
    expect(status.getAttribute('aria-live')).toBe('polite')
    expect(status.textContent?.trim()).toBe('')
    expect(twin.textContent?.trim()).toBe('Checking your name…')
    expect(twin.classList.contains('invisible')).toBe(true)
    expect(twin.getAttribute('aria-hidden')).toBe('true')
    expect(status.parentElement?.classList.contains('hilos-stack')).toBe(true)

    for (const phase of [null, 'checking', 'applying'] as const) {
      if (phase) tab.state(phase)
      await nextTick()
      expect(profileNameStatus()).toBe(status)
      expect(status.previousElementSibling).toBe(twin)
      expect(status.textContent?.trim()).toBe(
        phase === 'checking' ? 'Checking your name…' : '',
      )
      if (phase === 'checking') {
        expect(status.className).toBe(twin.className.replace(' invisible', ''))
        expect(
          status.querySelector('.spinner-border')?.getAttribute('aria-hidden'),
        ).toBe('true')
      }
      expect(byId('profile-rename-save').getAttribute('aria-busy')).toBe('true')
      expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(true)
      byId('profile-rename-save').click()
      field.form?.dispatchEvent(new Event('submit', { cancelable: true }))
      await nextTick()
      expect(tab.names()).toHaveLength(1)
      expect(tab.binding.name.get()).toBe('Ann')
      expect(field.matches(':disabled')).toBe(false)
      expect(bodyLock()?.disabled).toBe(false)
    }
    tab.identity(1, 'Bob')
    await nextTick()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    tab.state('done')
    await nextTick()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it.each(['close', 'cancel', 'Escape', 'backdrop'])(
    'allows %s during checking through the ordinary discard confirmation',
    async (exit) => {
      const tab = await sendStandard()
      tab.state('checking')
      await nextTick()
      typeInto('profile-name-input', 'Carol')
      await nextTick()
      expect((byId('profile-name-input') as HTMLInputElement).value).toBe(
        'Carol',
      )
      expect(byId('modal-close').hasAttribute('disabled')).toBe(false)
      expect(byId('profile-rename-cancel').hasAttribute('disabled')).toBe(false)
      if (exit === 'close') byId('modal-close').click()
      if (exit === 'cancel') byId('profile-rename-cancel').click()
      if (exit === 'Escape')
        byId('modal').dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      if (exit === 'backdrop') byId('modal').click()
      await flushPromises()
      expect(document.querySelector('[data-id="modal-confirm"]')).not.toBeNull()
      expect(document.activeElement).toBe(byId('modal-confirm'))
      byId('modal-confirm-cancel').click()
      await nextTick()
      expect((byId('profile-name-input') as HTMLInputElement).value).toBe(
        'Carol',
      )
      byId('profile-rename-cancel').click()
      await nextTick()
      byId('modal-confirm-discard').click()
      await nextTick()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(tab.flow.pending.get()).toBe(true)
      await tab.flow.open()
      await nextTick()
      expect((byId('profile-name-input') as HTMLInputElement).value).toBe(
        'Carol',
      )
      expect(profileNameStatus().textContent).toContain('Checking your name…')
      expect(tab.names()).toHaveLength(1)
      expect(
        tab.frames.filter((frame) => frame.action === 'hilos_step_up_start'),
      ).toHaveLength(1)
    },
  )

  it.each(['refused', 'done'] as const)(
    'does not reopen from a late %s and shows its outcome on manual open',
    async (phase) => {
      const tab = await sendStandard()
      tab.state('checking')
      tab.flow.close()
      await nextTick()
      tab.state(phase)
      if (phase === 'done') tab.identity(1, 'Bob')
      await nextTick()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      await tab.flow.open()
      await flushPromises()
      expect((byId('profile-name-input') as HTMLInputElement).value).toBe('Bob')
      expect(profileNameStatus().textContent?.trim()).toBe('')
      expect(byId('profile-rename-live-assertive').textContent).toBe(
        phase === 'refused' ? ' Exact server refusal.\nTry another name. ' : '',
      )
      expect(tab.names()).toHaveLength(1)
      expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(
        phase === 'done',
      )
    },
  )

  it('keeps a newer draft and focus through its own success without a false conflict', async () => {
    const tab = await sendStandard()
    tab.state('checking')
    typeInto('profile-name-input', 'Carol')
    const field = byId('profile-name-input') as HTMLInputElement
    field.focus()
    tab.identity(1, 'Bob')
    tab.state('done')
    await nextTick()
    expect(byId('profile-name-input')).toBe(field)
    expect(field.value).toBe('Carol')
    expect(document.activeElement).toBe(field)
    expect(document.querySelector('[data-id="profile-edit-notice"]')).toBeNull()
    expect(byId('profile-edit-notice-idle').getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(document.querySelector('[data-id="conflict-choices"]')).toBeNull()
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(false)
  })

  it('keeps Keep mine and Take theirs for a real external conflict and guards Enter', async () => {
    const tab = standardWindow()
    await tab.flow.open()
    await flushPromises()
    typeInto('profile-name-input', 'Bob')
    tab.identity(1, 'Carl')
    await nextTick()
    expect(byId('profile-edit-notice').textContent).toContain(
      'Changed elsewhere to "Carl".',
    )
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(true)
    ;(byId('profile-name-input') as HTMLInputElement).form?.dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    expect(tab.names()).toHaveLength(0)
    byId('conflict-accept-mine').click()
    await nextTick()
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(false)
    tab.identity(1, 'Dan')
    await nextTick()
    byId('conflict-accept-theirs').click()
    await nextTick()
    expect((byId('profile-name-input') as HTMLInputElement).value).toBe('Dan')
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(true)
  })

  it('retains the independent connection lock while a name check is pending', async () => {
    const tab = await sendStandard()
    tab.state('checking')
    const field = byId('profile-name-input') as HTMLInputElement
    field.focus()
    tab.context.connection.close()
    await nextTick()
    expect(byId('profile-name-input')).toBe(field)
    expect(field.matches(':disabled')).toBe(true)
    expect(
      (byId('modal-interaction-scope') as HTMLFieldSetElement).disabled,
    ).toBe(true)
    tryClosing('profile-rename-cancel')
    await nextTick()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    expect(tab.names()).toHaveLength(1)
  })
})

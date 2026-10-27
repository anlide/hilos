import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import {
  createConnectionInteractionState,
  createHilosProfileRenameFlow,
  createSignal,
  PROFILE_RENAME_MEMORY_STORAGE_KEY,
  type ActionLifecycle,
  type HilosProfileRename as HilosProfileRenameBinding,
} from '@hilos/core'
import { HilosConnectionInteractionContext } from '../src/hilosConnectionInteraction.js'
import { HilosProfileRename } from '../src/profile/HilosProfileRename.js'
import { profileNameStatus, profileRenameTab } from './profileRenameHarness.js'

const disposers: Array<() => void> = []
beforeEach(() => sessionStorage.clear())
afterEach(() => {
  cleanup()
  for (const stop of disposers.splice(0).reverse()) stop()
  sessionStorage.clear()
})

function setup() {
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const name = createSignal('Ann')
  const refusal = createSignal<string | null>(null)
  const rename: HilosProfileRenameBinding = {
    send: () => true,
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
          return {
            done:
              action === 'hilos_step_up_start'
                ? Promise.resolve({
                    reply: {
                      required: true,
                      purpose: 'change your name',
                      method: 'password',
                    },
                  })
                : action === 'hilos_step_up_confirm'
                  ? Promise.resolve({ reply: [] })
                  : Promise.reject(new Error(`unexpected ${action}`)),
          }
        },
      } as unknown as ActionLifecycle,
    },
    name,
    rename,
  )
  disposers.push(flow.dispose)
  const view = render(<HilosProfileRename flow={flow} />)
  const node = (id: string) =>
    document.querySelector(`[data-id="${id}"]`) as HTMLElement

  return { flow, dispatched, view, node }
}

describe('HilosProfileRename', () => {
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, node, dispatched } = setup()
    await act(async () => {
      await flow.open()
    })

    const form = node('profile-name-step-up')
    const confirm = node('profile-name-step-up-confirm')

    expect(confirm.getAttribute('type')).toBe('submit')
    expect(confirm.getAttribute('form')).toBe(form.id)

    await act(async () => {
      fireEvent.submit(form)
    })
    expect(dispatched.some((d) => d.action === 'hilos_step_up_confirm')).toBe(
      false,
    )

    fireEvent.change(node('step-up-password'), { target: { value: 'secret' } })
    await act(async () => {
      fireEvent.submit(form)
    })
    const call = dispatched.find((d) => d.action === 'hilos_step_up_confirm')
    expect(call).toBeDefined()
    expect(call?.payload).toMatchObject({
      password: 'secret',
    })
  })
})

function byId(id: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!found) throw new Error(`Missing ${id}`)
  return found
}

function nameInput(): HTMLInputElement {
  return byId('profile-name-input') as HTMLInputElement
}

/** The four ordinary close gestures, including the modal's dirty guard. */
function tryClosing(cancel: string): void {
  fireEvent.click(byId('modal-close'))
  fireEvent.click(byId(cancel))
  fireEvent.keyDown(byId('modal'), { key: 'Escape' })
  fireEvent.click(byId('modal'))
}

function standardWindow(options: Parameters<typeof profileRenameTab>[1] = {}) {
  const tab = profileRenameTab(true, options)
  const flow = createHilosProfileRenameFlow(
    tab.context,
    tab.binding.name,
    tab.binding.rename,
    { remember: true },
  )
  const interaction = createConnectionInteractionState(tab.context.connection)
  disposers.push(tab.dispose, interaction.dispose, flow.dispose)
  flow.start()
  const view = render(
    <HilosConnectionInteractionContext.Provider value={interaction}>
      <HilosProfileRename flow={flow} />
    </HilosConnectionInteractionContext.Provider>,
  )
  return { ...tab, flow, view }
}

async function sendStandard(submit: 'Save' | 'Enter' = 'Save') {
  const tab = standardWindow()
  await act(async () => {
    await tab.flow.open()
  })
  fireEvent.change(nameInput(), { target: { value: 'Bob' } })
  if (submit === 'Save') fireEvent.click(byId('profile-rename-save'))
  else fireEvent.submit(nameInput().form!)
  return tab
}

describe('standard name state in the real window', () => {
  it.each(['Save', 'Enter'] as const)(
    'keeps one %s send and the same polite status and twin through pre-ack, checking and applying',
    async (submit) => {
      const tab = await sendStandard(submit)
      expect(screen.getByRole('dialog', { name: 'Change name' })).toBe(
        byId('modal'),
      )
      const field = nameInput()
      const status = profileNameStatus()
      const twin = status.previousElementSibling!
      expect(status.getAttribute('aria-live')).toBe('polite')
      expect(status.textContent).toBe('')
      expect(twin.textContent).toBe('Checking your name…')
      expect(twin.classList.contains('invisible')).toBe(true)
      expect(twin.getAttribute('aria-hidden')).toBe('true')
      expect(status.parentElement?.classList.contains('hilos-stack')).toBe(true)

      for (const phase of [null, 'checking', 'applying'] as const) {
        if (phase) act(() => tab.state(phase))
        expect(profileNameStatus()).toBe(status)
        expect(status.previousElementSibling).toBe(twin)
        expect(status.textContent).toBe(
          phase === 'checking' ? 'Checking your name…' : '',
        )
        if (phase === 'checking') {
          expect(status.className).toBe(
            twin.className.replace(' invisible', ''),
          )
          expect(
            status
              .querySelector('.spinner-border')
              ?.getAttribute('aria-hidden'),
          ).toBe('true')
          expect(status.innerHTML).toBe(twin.innerHTML)
        }
        expect(byId('profile-rename-save').getAttribute('aria-busy')).toBe(
          'true',
        )
        expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(true)
        fireEvent.click(byId('profile-rename-save'))
        fireEvent.submit(field.form!)
        expect(tab.names()).toHaveLength(1)
        expect(tab.binding.name.get()).toBe('Ann')
        expect(field.matches(':disabled')).toBe(false)
        expect(
          (byId('modal-interaction-scope') as HTMLFieldSetElement).disabled,
        ).toBe(false)
      }
      act(() => tab.identity(1, 'Bob'))
      expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
      act(() => tab.state('done'))
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
    },
  )

  it.each(['close', 'cancel', 'Escape', 'backdrop'])(
    'allows %s while checking, preserves the edited draft and reopens the accepted attempt',
    async (exit) => {
      const tab = await sendStandard()
      act(() => tab.state('checking'))
      fireEvent.change(nameInput(), { target: { value: 'Carol' } })
      expect(nameInput().value).toBe('Carol')
      expect(byId('modal-close').hasAttribute('disabled')).toBe(false)
      expect(byId('profile-rename-cancel').hasAttribute('disabled')).toBe(false)
      if (exit === 'close') fireEvent.click(byId('modal-close'))
      if (exit === 'cancel') fireEvent.click(byId('profile-rename-cancel'))
      if (exit === 'Escape') fireEvent.keyDown(byId('modal'), { key: 'Escape' })
      if (exit === 'backdrop') fireEvent.click(byId('modal'))
      expect(document.activeElement).toBe(byId('modal-confirm'))
      fireEvent.click(byId('modal-confirm-cancel'))
      expect(nameInput().value).toBe('Carol')
      fireEvent.click(byId('profile-rename-cancel'))
      fireEvent.click(byId('modal-confirm-discard'))
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(tab.flow.pending.get()).toBe(true)
      await act(async () => {
        await tab.flow.open()
      })
      expect(nameInput().value).toBe('Carol')
      expect(profileNameStatus().textContent).toBe('Checking your name…')
      expect(tab.names()).toHaveLength(1)
      expect(
        tab.frames.filter((frame) => frame.action === 'hilos_step_up_start'),
      ).toHaveLength(1)
    },
  )

  it.each(['refused', 'done'] as const)(
    'does not reopen from late %s and presents the outcome on manual open',
    async (phase) => {
      const tab = await sendStandard()
      act(() => {
        tab.state('checking')
        tab.flow.close()
      })
      await act(async () => {
        tab.state(phase)
        if (phase === 'done') tab.identity(1, 'Bob')
      })
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      await act(async () => {
        await tab.flow.open()
      })
      expect(nameInput().value).toBe('Bob')
      expect(profileNameStatus().textContent).toBe('')
      expect(byId('profile-rename-live-assertive').textContent).toBe(
        phase === 'refused' ? ' Exact server refusal.\nTry another name. ' : '',
      )
      expect(tab.names()).toHaveLength(1)
      expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(
        phase === 'done',
      )
    },
  )

  it('preserves a newer draft and focus through own echo and done without a false conflict', async () => {
    const tab = await sendStandard()
    act(() => tab.state('checking'))
    fireEvent.change(nameInput(), { target: { value: 'Carol' } })
    const field = nameInput()
    field.focus()
    act(() => {
      tab.identity(1, 'Bob')
      tab.state('done')
    })
    expect(nameInput()).toBe(field)
    expect(field.value).toBe('Carol')
    expect(document.activeElement).toBe(field)
    expect(document.querySelector('[data-id="profile-edit-notice"]')).toBeNull()
    expect(byId('profile-edit-notice-idle').getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(document.querySelector('[data-id="conflict-choices"]')).toBeNull()
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(false)
  })

  it('offers both choices for an external conflict and guards Enter until the choice', async () => {
    const tab = standardWindow()
    await act(async () => {
      await tab.flow.open()
    })
    fireEvent.change(nameInput(), { target: { value: 'Bob' } })
    act(() => tab.identity(1, 'Carl'))
    expect(byId('profile-edit-notice').textContent).toContain(
      'Changed elsewhere to "Carl".',
    )
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(true)
    fireEvent.submit(nameInput().form!)
    expect(tab.names()).toHaveLength(0)
    fireEvent.click(byId('conflict-accept-mine'))
    expect(nameInput().value).toBe('Bob')
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(false)
    act(() => tab.identity(1, 'Dan'))
    fireEvent.click(byId('conflict-accept-theirs'))
    expect(nameInput().value).toBe('Dan')
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(true)
    fireEvent.submit(nameInput().form!)
    expect(tab.names()).toHaveLength(0)
  })

  it('retains the independent connection interaction guard during checking', async () => {
    const tab = await sendStandard()
    act(() => tab.state('checking'))
    const field = nameInput()
    field.focus()
    act(() => tab.context.connection.close())
    expect(nameInput()).toBe(field)
    expect(field.matches(':disabled')).toBe(true)
    expect(
      (byId('modal-interaction-scope') as HTMLFieldSetElement).disabled,
    ).toBe(true)
    tryClosing('profile-rename-cancel')
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    expect(tab.names()).toHaveLength(1)
  })

  it('holds credentials and every close gesture only during step-up confirmation, then focuses the name', async () => {
    const tab = standardWindow({
      stepUp: {
        required: true,
        purpose: 'change your name',
        method: 'password',
      },
      hold: ['hilos_step_up_confirm'],
    })
    await act(async () => {
      await tab.flow.open()
    })
    expect(screen.getByRole('dialog', { name: "Confirm it's you" })).toBe(
      byId('modal'),
    )
    const field = byId('step-up-password') as HTMLInputElement
    expect(document.activeElement).toBe(field)
    expect(field.matches(':disabled')).toBe(false)
    fireEvent.change(field, { target: { value: 'secret-password' } })
    fireEvent.submit(byId('profile-name-step-up'))
    expect(field.matches(':disabled')).toBe(true)
    expect(byId('profile-name-step-up-cancel').hasAttribute('disabled')).toBe(
      true,
    )
    expect(byId('modal-close').hasAttribute('disabled')).toBe(true)
    expect(byId('profile-name-step-up-confirm').getAttribute('aria-busy')).toBe(
      'true',
    )
    tryClosing('profile-name-step-up-cancel')
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    fireEvent.submit(byId('profile-name-step-up'))
    const confirms = () =>
      tab.frames.filter((frame) => frame.action === 'hilos_step_up_confirm')
    expect(confirms()).toHaveLength(1)
    expect(confirms()[0]?.data).toMatchObject({ password: 'secret-password' })
    const stored = JSON.stringify(sessionStorage)
    expect(stored).not.toContain('secret-password')
    expect(
      Object.keys(
        JSON.parse(sessionStorage.getItem(PROFILE_RENAME_MEMORY_STORAGE_KEY)!),
      ),
    ).toEqual([
      'version',
      'userId',
      'open',
      'step',
      'draft',
      'checkpoint',
      'requestId',
    ])
    await act(async () => {
      tab.reply(confirms()[0]!, 'That password is not right')
    })
    expect(byId('step-up-password')).toBe(field)
    expect(field.value).toBe('secret-password')
    expect(field.matches(':disabled')).toBe(false)
    expect(byId('step-up-error').textContent).toContain(
      'That password is not right',
    )
    fireEvent.submit(byId('profile-name-step-up'))
    expect(confirms()).toHaveLength(2)
    await act(async () => {
      tab.reply(confirms()[1]!)
    })
    expect(screen.getByRole('dialog', { name: 'Change name' })).toBe(
      byId('modal'),
    )
    expect(document.activeElement).toBe(nameInput())
    expect(byId('profile-rename-cancel').hasAttribute('disabled')).toBe(false)
    expect(byId('modal-close').hasAttribute('disabled')).toBe(false)
    expect(nameInput().value).toBe('Ann')
    expect(tab.names()).toHaveLength(0)
  })
})

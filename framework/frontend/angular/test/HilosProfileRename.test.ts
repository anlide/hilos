import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  bindCodeSendProgress,
  CODE_SEND_PURPOSE_STEP_UP,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENT,
  createConnectionInteractionState,
  createHilosProfileRenameFlow,
  createSignal,
  PROFILE_RENAME_MEMORY_STORAGE_KEY,
  SIGNAL_CODE_SEND_PROGRESS,
  type ActionLifecycle,
  type HilosConnection,
  type HilosProfileRename as HilosProfileRenameBinding,
} from '@hilos/core/base'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HILOS_CONNECTION_INTERACTION } from '../src/hilosConnectionInteraction.js'
import { HilosProfileRename } from '../src/profile/HilosProfileRename.js'
import { profileNameStatus, profileRenameTab } from './profileRenameHarness.js'

const mounted: Array<ComponentFixture<unknown>> = []
const disposers: Array<() => void> = []

beforeEach(() => sessionStorage.clear())

afterEach(() => {
  for (const fixture of mounted.splice(0).reverse()) fixture.destroy()
  for (const stop of disposers.splice(0).reverse()) stop()
  sessionStorage.clear()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  TestBed.resetTestingModule()
  tellSend({ state: null })
})

async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

/**
 * Tell the session's send line as the server frames it, through the binding the
 * application boots with; the binding is let go once the frame is in, and a
 * null state takes the line away.
 *
 * @param line The frame's fields beside its message, which is none.
 * @param fixture The window to draw once the frame is in, when there is one.
 */
function tellSend(
  line: Record<string, unknown>,
  fixture?: ComponentFixture<unknown>,
): void {
  const sendListeners: ((signal: { type: string; data: unknown }) => void)[] =
    []
  const unbind = bindCodeSendProgress({
    on: (_event: string, listener: (signal: never) => void) => {
      sendListeners.push(
        listener as (signal: { type: string; data: unknown }) => void,
      )
      return () => undefined
    },
  } as unknown as HilosConnection)
  for (const listener of sendListeners)
    listener({
      type: SIGNAL_CODE_SEND_PROGRESS,
      data: { message: null, ...line },
    })
  unbind()
  fixture?.detectChanges()
}

/**
 * One frame of the confirmation's send line.
 *
 * @param ticket The send the line follows.
 * @param state The step the send reached.
 * @param more The provider's detail, when the frame carries one.
 */
function confirmationLine(
  ticket: string,
  state: string,
  more: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    state,
    channel: 'email',
    purpose: CODE_SEND_PURPOSE_STEP_UP,
    ticket,
    ...more,
  }
}

/** What the window's calm region says now, past the template's own spacing. */
function calmText(): string | undefined {
  return document
    .querySelector('[data-id="profile-rename-live-polite"]')!
    .textContent?.trim()
}

/**
 * The block's element-host, or else its root, or else the first thing inside
 * either, that matches the selector: the host and the root of a block are the
 * likeliest places for a voice to return.
 *
 * @param block The root of a step-up or send block, drawn inside its host.
 * @param selector What a live voice looks like.
 */
function selfOrInside(block: Element, selector: string): Element | null {
  const host = block.closest('hilos-step-up-step, hilos-send-progress') ?? block
  return host.matches(selector) ? host : host.querySelector(selector)
}

/** A confirmation that emailed its code, whose resend gate opens in a minute. */
const EMAIL_STEP = {
  required: true,
  purpose: 'change your name',
  method: 'email_code',
  destination: 'a@example.test',
  send: { sent: true, resendAt: Date.now() + 60_000, expiresAt: null },
}

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
  const fixture = TestBed.createComponent(HilosProfileRename)
  fixture.componentRef.setInput('flow', flow)
  fixture.detectChanges()

  const node = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)!

  return { flow, dispatched, fixture, node }
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
  TestBed.configureTestingModule({
    providers: [
      {
        provide: HILOS_CONNECTION_INTERACTION,
        useValue: createSignal(interaction),
      },
    ],
  })
  const fixture = TestBed.createComponent(HilosProfileRename)
  mounted.push(fixture)
  fixture.componentRef.setInput('flow', flow)
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  const query = (id: string): HTMLElement | null =>
    root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  const node = (id: string): HTMLElement => {
    const found = query(id)
    if (!found) throw new Error(`Missing ${id}`)
    return found
  }
  return {
    tab,
    flow,
    fixture,
    node,
    exists: (id: string) => query(id) !== null,
  }
}

function nameInput(node: (id: string) => HTMLElement): HTMLInputElement {
  return node('profile-name-input') as HTMLInputElement
}

async function openName(
  flow: ReturnType<typeof createHilosProfileRenameFlow>,
  fixture: ComponentFixture<unknown>,
): Promise<void> {
  await flow.open()
  await settle(fixture)
}

function changeName(
  input: HTMLInputElement,
  fixture: ComponentFixture<unknown>,
  value: string,
): void {
  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
  fixture.detectChanges()
}

function submitName(
  input: HTMLInputElement,
  fixture: ComponentFixture<unknown>,
): void {
  input.form?.dispatchEvent(
    new Event('submit', { cancelable: true, bubbles: true }),
  )
  fixture.detectChanges()
}

async function dismissDirtyWindow(
  node: (id: string) => HTMLElement,
  fixture: ComponentFixture<unknown>,
): Promise<void> {
  node('profile-rename-cancel').click()
  fixture.detectChanges()
  node('modal-confirm-discard').click()
  await settle(fixture)
}

describe('HilosProfileRename', () => {
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, dispatched, fixture, node } = setup()
    await flow.open()
    await settle(fixture)

    const form = node('profile-name-step-up')
    const confirm = node('profile-name-step-up-confirm')

    expect(confirm.getAttribute('type')).toBe('submit')
    expect(confirm.getAttribute('form')).toBe(form.id)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle(fixture)
    expect(dispatched.some((d) => d.action === 'hilos_step_up_confirm')).toBe(
      false,
    )

    const input = node('step-up-password') as HTMLInputElement
    input.value = 'secret'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await settle(fixture)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle(fixture)

    const call = dispatched.find((d) => d.action === 'hilos_step_up_confirm')
    expect(call).toBeDefined()
    expect(call?.payload).toMatchObject({
      password: 'secret',
    })

    flow.dispose()
    fixture.destroy()
  })
})

describe('the standard rename binding in the Angular window', () => {
  it.each(['Save', 'Enter'] as const)(
    'sends once from %s and keeps the checking region steady through pre-ack, checking and applying',
    async (submit) => {
      const window = standardWindow()
      await openName(window.flow, window.fixture)
      const dialog = window.node('modal')
      const heading = dialog.querySelector('h5')!
      expect(heading.textContent?.trim()).toBe('Change name')
      expect(dialog.getAttribute('aria-label')).toBe(
        heading.textContent?.trim(),
      )
      const input = nameInput(window.node)
      changeName(input, window.fixture, 'Bob')
      if (submit === 'Save') window.node('profile-rename-save').click()
      else submitName(input, window.fixture)
      await settle(window.fixture)

      const status = profileNameStatus()
      const twin = status.previousElementSibling as HTMLElement
      const save = window.node('profile-rename-save')
      expect(status.getAttribute('aria-live')).toBe('polite')
      expect(status.textContent?.trim()).toBe('')
      expect(twin.textContent).toContain('Checking your name…')
      expect(twin.classList.contains('invisible')).toBe(true)
      expect(twin.getAttribute('aria-hidden')).toBe('true')
      expect(status.parentElement?.classList.contains('hilos-stack')).toBe(true)
      expect(save.getAttribute('aria-busy')).toBe('true')
      expect((save as HTMLButtonElement).disabled).toBe(true)

      for (const phase of [null, 'checking', 'applying'] as const) {
        if (phase !== null) window.tab.state(phase)
        await settle(window.fixture)
        expect(profileNameStatus()).toBe(status)
        expect(status.previousElementSibling).toBe(twin)
        expect(status.textContent?.trim()).toBe(
          phase === 'checking' ? 'Checking your name…' : '',
        )
        expect(status.querySelector('.spinner-border') !== null).toBe(
          phase === 'checking',
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
        }
        expect(save.getAttribute('aria-busy')).toBe('true')
        expect((save as HTMLButtonElement).disabled).toBe(true)
        expect(input.matches(':disabled')).toBe(false)
        expect(window.node('profile-rename-cancel').matches(':disabled')).toBe(
          false,
        )
        expect(window.node('modal-close').matches(':disabled')).toBe(false)
        expect(
          (window.node('modal-interaction-scope') as HTMLFieldSetElement)
            .disabled,
        ).toBe(false)
        save.dispatchEvent(new MouseEvent('click', { bubbles: true }))
        submitName(input, window.fixture)
        expect(window.tab.names()).toHaveLength(1)
        expect(window.tab.binding.name.get()).toBe('Ann')
      }

      window.tab.identity(1, 'Bob')
      window.tab.state('done')
      await settle(window.fixture)
      expect(window.exists('modal')).toBe(false)
      expect(
        window.tab.frames.filter(
          (frame) => frame.action === 'hilos_step_up_start',
        ),
      ).toHaveLength(1)
    },
  )

  it.each(['close', 'cancel', 'Escape', 'backdrop'] as const)(
    'keeps %s available during checking and reopens the same accepted attempt',
    async (exit) => {
      const window = standardWindow()
      await openName(window.flow, window.fixture)
      const input = nameInput(window.node)
      changeName(input, window.fixture, 'Bob')
      window.node('profile-rename-save').click()
      window.tab.state('checking')
      changeName(input, window.fixture, 'Carol')
      await settle(window.fixture)

      expect(input.value).toBe('Carol')
      expect(window.node('modal-close').matches(':disabled')).toBe(false)
      expect(window.node('profile-rename-cancel').matches(':disabled')).toBe(
        false,
      )
      if (exit === 'close') window.node('modal-close').click()
      if (exit === 'cancel') window.node('profile-rename-cancel').click()
      if (exit === 'Escape')
        window
          .node('modal')
          .dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
          )
      if (exit === 'backdrop') window.node('modal').click()
      window.fixture.detectChanges()
      expect(window.exists('modal-confirm')).toBe(true)
      window.node('modal-confirm-discard').click()
      await settle(window.fixture)

      expect(window.exists('modal')).toBe(false)
      expect(window.flow.pending.get()).toBe(true)
      expect(
        JSON.parse(sessionStorage.getItem(PROFILE_RENAME_MEMORY_STORAGE_KEY)!),
      ).toMatchObject({ open: false, draft: 'Carol' })
      await openName(window.flow, window.fixture)
      expect(nameInput(window.node).value).toBe('Carol')
      expect(profileNameStatus().textContent).toContain('Checking your name…')
      expect(window.tab.names()).toHaveLength(1)
      expect(
        window.tab.frames.filter(
          (frame) => frame.action === 'hilos_step_up_start',
        ),
      ).toHaveLength(1)

      window.tab.identity(1, 'Bob')
      window.tab.state('done')
      await settle(window.fixture)
      expect(nameInput(window.node).value).toBe('Carol')
      changeName(nameInput(window.node), window.fixture, 'Bob')
      window.node('profile-rename-cancel').click()
      await settle(window.fixture)
      expect(window.exists('modal')).toBe(false)
    },
  )

  it.each(['refused', 'done'] as const)(
    'does not reopen after an explicit close and late %s, then shows the authoritative result on manual open',
    async (phase) => {
      const window = standardWindow()
      await openName(window.flow, window.fixture)
      changeName(nameInput(window.node), window.fixture, 'Bob')
      window.node('profile-rename-save').click()
      window.tab.state('checking')
      await dismissDirtyWindow(window.node, window.fixture)

      window.tab.state(phase)
      if (phase === 'done') window.tab.identity(1, 'Bob')
      await settle(window.fixture)
      expect(window.exists('modal')).toBe(false)

      await openName(window.flow, window.fixture)
      expect(nameInput(window.node).value).toBe('Bob')
      expect(window.tab.names()).toHaveLength(1)
      expect(
        window.node('profile-rename-live-assertive').textContent?.trim(),
      ).toBe(
        phase === 'refused' ? 'Exact server refusal.\nTry another name.' : '',
      )
      if (phase === 'refused')
        expect(window.flow.refusal.get()).toBe(
          ' Exact server refusal.\nTry another name. ',
        )
      expect(
        (window.node('profile-rename-save') as HTMLButtonElement).disabled,
      ).toBe(phase === 'done')
      if (phase === 'refused')
        await dismissDirtyWindow(window.node, window.fixture)
      else {
        window.node('profile-rename-cancel').click()
        await settle(window.fixture)
      }
      expect(window.exists('modal')).toBe(false)
    },
  )

  it('rebases its own echo and done without losing a newer draft or creating a conflict', async () => {
    const window = standardWindow()
    await openName(window.flow, window.fixture)
    const input = nameInput(window.node)
    changeName(input, window.fixture, 'Bob')
    window.node('profile-rename-save').click()
    window.tab.state('checking')
    changeName(input, window.fixture, 'Carol')
    input.focus()

    window.tab.identity(1, 'Bob')
    window.tab.state('done')
    await settle(window.fixture)
    expect(nameInput(window.node)).toBe(input)
    expect(input.value).toBe('Carol')
    expect(document.activeElement).toBe(input)
    expect(window.exists('profile-edit-notice')).toBe(false)
    expect(
      window.node('profile-edit-notice-idle').getAttribute('aria-hidden'),
    ).toBe('true')
    expect(window.exists('conflict-choices')).toBe(false)
    expect(
      (window.node('profile-rename-save') as HTMLButtonElement).disabled,
    ).toBe(false)
  })

  it('closes an open unchanged sending form on the matching owner reply', async () => {
    const window = standardWindow()
    await openName(window.flow, window.fixture)
    changeName(nameInput(window.node), window.fixture, 'Bob')
    window.node('profile-rename-save').click()
    window.tab.state('checking')
    window.tab.identity(1, 'Bob')
    window.tab.state('done')
    await settle(window.fixture)
    expect(window.exists('modal')).toBe(false)
  })

  it('offers conflict choices and refuses Enter until the user resolves the conflict', async () => {
    const window = standardWindow()
    await openName(window.flow, window.fixture)
    const input = nameInput(window.node)
    changeName(input, window.fixture, 'Bob')
    window.tab.identity(1, 'Carl')
    await settle(window.fixture)
    expect(window.node('profile-edit-notice').textContent).toContain(
      'Changed elsewhere to "Carl".',
    )
    expect(
      (window.node('profile-rename-save') as HTMLButtonElement).disabled,
    ).toBe(true)
    submitName(input, window.fixture)
    expect(window.tab.names()).toHaveLength(0)
    window.node('conflict-accept-mine').click()
    await settle(window.fixture)
    expect(input.value).toBe('Bob')
    expect(
      (window.node('profile-rename-save') as HTMLButtonElement).disabled,
    ).toBe(false)
    window.tab.identity(1, 'Dan')
    await settle(window.fixture)
    window.node('conflict-accept-theirs').click()
    await settle(window.fixture)
    expect(input.value).toBe('Dan')
    expect(
      (window.node('profile-rename-save') as HTMLButtonElement).disabled,
    ).toBe(true)
    submitName(input, window.fixture)
    expect(window.tab.names()).toHaveLength(0)
  })

  it('keeps the connection interaction blocker independent from name pending', async () => {
    const window = standardWindow()
    await openName(window.flow, window.fixture)
    changeName(nameInput(window.node), window.fixture, 'Bob')
    window.node('profile-rename-save').click()
    window.tab.state('checking')
    const input = nameInput(window.node)
    input.focus()
    window.tab.context.connection.close()
    await settle(window.fixture)

    expect(nameInput(window.node)).toBe(input)
    expect(input.matches(':disabled')).toBe(true)
    expect(
      (window.node('modal-interaction-scope') as HTMLFieldSetElement).disabled,
    ).toBe(true)
    window.node('modal-close').click()
    window.node('profile-rename-cancel').click()
    window
      .node('modal')
      .dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
    window.node('modal').click()
    await settle(window.fixture)
    expect(window.exists('modal')).toBe(true)
    expect(window.exists('modal-confirm')).toBe(false)
    expect(window.tab.names()).toHaveLength(1)

    window.tab.reconnect()
    await settle(window.fixture)
  })

  it('locks credentials and every close path only during confirmation, then focuses the name', async () => {
    const window = standardWindow({
      stepUp: {
        required: true,
        purpose: 'change your name',
        method: 'password',
      },
      hold: ['hilos_step_up_confirm'],
    })
    await openName(window.flow, window.fixture)
    const dialog = window.node('modal')
    const heading = dialog.querySelector('h5')!
    expect(heading.textContent?.trim()).toBe("Confirm it's you")
    expect(dialog.getAttribute('aria-label')).toBe(heading.textContent?.trim())
    const field = window.node('step-up-password') as HTMLInputElement
    expect(document.activeElement).toBe(field)
    field.value = 'secret-password'
    field.dispatchEvent(new Event('input', { bubbles: true }))
    const confirm = vi.spyOn(window.flow, 'confirmStepUp')
    window
      .node('profile-name-step-up')
      .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await settle(window.fixture)

    expect(field.matches(':disabled')).toBe(true)
    expect(
      window.node('profile-name-step-up-cancel').matches(':disabled'),
    ).toBe(true)
    expect(window.node('modal-close').matches(':disabled')).toBe(true)
    expect(
      window.node('profile-name-step-up-confirm').getAttribute('aria-busy'),
    ).toBe('true')
    window.node('modal-close').click()
    window.node('profile-name-step-up-cancel').click()
    window
      .node('modal')
      .dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
    window.node('modal').click()
    window
      .node('profile-name-step-up')
      .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await settle(window.fixture)
    const confirmations = () =>
      window.tab.frames.filter(
        (frame) => frame.action === 'hilos_step_up_confirm',
      )
    expect(confirmations()).toHaveLength(1)
    expect(confirmations()[0]?.data).toMatchObject({
      password: 'secret-password',
    })
    const raw = sessionStorage.getItem(PROFILE_RENAME_MEMORY_STORAGE_KEY)!
    expect(raw).not.toContain('secret-password')
    expect(JSON.parse(raw)).toMatchObject({
      version: 1,
      userId: 1,
      open: true,
      step: 'step-up',
      draft: '',
      checkpoint: null,
      requestId: null,
    })

    window.tab.reply(confirmations()[0]!, 'That password is not right')
    await confirm.mock.results[0]!.value
    await settle(window.fixture)
    expect(window.node('step-up-password')).toBe(field)
    expect(field.value).toBe('secret-password')
    expect(field.matches(':disabled')).toBe(false)
    expect(window.node('step-up-error').textContent).toContain(
      'That password is not right',
    )
    window
      .node('profile-name-step-up')
      .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await settle(window.fixture)
    expect(confirmations()).toHaveLength(2)
    window.tab.reply(confirmations()[1]!)
    await confirm.mock.results[1]!.value
    await settle(window.fixture)
    expect(heading.textContent?.trim()).toBe('Change name')
    expect(dialog.getAttribute('aria-label')).toBe(heading.textContent?.trim())
    expect(document.activeElement).toBe(nameInput(window.node))
    expect(window.node('profile-rename-cancel').matches(':disabled')).toBe(
      false,
    )
    expect(window.node('modal-close').matches(':disabled')).toBe(false)
    expect(nameInput(window.node).value).toBe('Ann')
    expect(window.tab.names()).toHaveLength(0)
  })
})

describe('the confirmation send line in the Angular name window', () => {
  it("says the confirmation's line to its address through a region that outlives the step and falls silent on the form", async () => {
    const window = standardWindow({
      stepUp: EMAIL_STEP,
      hold: ['hilos_step_up_confirm'],
    })
    await openName(window.flow, window.fixture)
    const calm = window.node('profile-rename-live-polite')
    expect(calm.getAttribute('role')).toBe('status')
    expect(calm.getAttribute('aria-live')).toBe('polite')
    expect(calm.closest('[aria-modal="true"]')).not.toBeNull()
    expect(calmText()).toBe('')

    tellSend(confirmationLine('this', CODE_SEND_STATE_QUEUED), window.fixture)
    expect(calmText()).toBe('Queued for sending…')
    tellSend(confirmationLine('this', CODE_SEND_STATE_SENT), window.fixture)
    expect(window.node('profile-rename-live-polite')).toBe(calm)
    expect(calmText()).toBe('Sent to a@example.test')
    tellSend(
      confirmationLine('this', CODE_SEND_STATE_FAILED, {
        detail: 'mailbox unavailable',
      }),
      window.fixture,
    )
    expect(calmText()).toBe('Could not send: mailbox unavailable')

    // The countdown and the line show on the row, which has no voice of its own.
    const row = window.node('step-up-send')
    expect(row.textContent).toContain('Could not send: mailbox unavailable')
    expect(row.textContent).toContain('Send again in')
    expect(calmText()).not.toContain('Send again in')
    expect(
      selfOrInside(
        window.node('step-up'),
        '[role="status"], [role="alert"], [aria-live]',
      ),
    ).toBeNull()

    // A refusal interrupts through its own region; the send line stays calm.
    const confirmations = () =>
      window.tab.frames.filter(
        (frame) => frame.action === 'hilos_step_up_confirm',
      )
    const confirm = vi.spyOn(window.flow, 'confirmStepUp')
    const code = window.node('step-up-code') as HTMLInputElement
    code.value = '000000'
    code.dispatchEvent(new Event('input', { bubbles: true }))
    window
      .node('profile-name-step-up')
      .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await settle(window.fixture)
    expect(confirmations()).toHaveLength(1)
    window.tab.reply(confirmations()[0]!, 'That code is not right')
    await confirm.mock.results[0]!.value
    await settle(window.fixture)
    expect(
      window.node('profile-rename-live-assertive').textContent?.trim(),
    ).toBe('That code is not right')
    expect(window.node('profile-rename-live-polite')).toBe(calm)
    expect(calmText()).toBe('Could not send: mailbox unavailable')

    code.value = '123456'
    code.dispatchEvent(new Event('input', { bubbles: true }))
    window
      .node('profile-name-step-up')
      .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }))
    await settle(window.fixture)
    expect(confirmations()).toHaveLength(2)
    window.tab.reply(confirmations()[1]!)
    await confirm.mock.results[1]!.value
    await settle(window.fixture)
    expect(nameInput(window.node)).not.toBeNull()
    expect(window.node('profile-rename-live-polite')).toBe(calm)
    expect(calmText()).toBe('')
  })

  it.each([
    {
      method: 'sms_code',
      destination: '+15550001111',
      said: 'Sent to +15550001111',
    },
    { method: 'passkey', destination: undefined, said: '' },
  ])(
    'says the confirmation line of a $method step only when a code was sent',
    async ({ method, destination, said }) => {
      const window = standardWindow({
        stepUp: {
          required: true,
          purpose: 'change your name',
          method,
          destination,
        },
      })
      await openName(window.flow, window.fixture)
      const calm = window.node('profile-rename-live-polite')
      expect(calmText()).toBe('')

      tellSend(confirmationLine('this', CODE_SEND_STATE_SENT), window.fixture)
      expect(window.node('profile-rename-live-polite')).toBe(calm)
      expect(calmText()).toBe(said)
    },
  )

  it('leaves the line of a closed window behind and says the next attempt in a new dialog', async () => {
    const window = standardWindow({ stepUp: EMAIL_STEP })
    await openName(window.flow, window.fixture)
    const first = window.node('modal')
    tellSend(confirmationLine('closed', CODE_SEND_STATE_SENT), window.fixture)
    expect(calmText()).toBe('Sent to a@example.test')

    window.flow.close()
    await settle(window.fixture)
    expect(window.exists('modal')).toBe(false)

    // The window opens as a new dialog; the region it projects stands in it
    // with nothing to say.
    await openName(window.flow, window.fixture)
    expect(window.node('profile-name-step-up')).not.toBeNull()
    expect(window.node('modal')).not.toBe(first)
    expect(
      window.node('profile-rename-live-polite').closest('[aria-modal="true"]'),
    ).toBe(window.node('modal'))
    expect(calmText()).toBe('')
    tellSend(confirmationLine('again', CODE_SEND_STATE_QUEUED), window.fixture)
    expect(calmText()).toBe('Queued for sending…')
  })
})

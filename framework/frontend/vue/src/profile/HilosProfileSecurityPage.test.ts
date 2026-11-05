// Covers what the profile's security page view owns rather than the core store
// (HIL-494): a form sent again while its action is out dispatches nothing, and
// the pressed button keeps its look while it does (HIL-1766), the step a modal
// moves to takes the focus, and backup codes shown once ask before the modal
// closes. Connecting an app is a protected operation (HIL-1138): the
// modal opens on the server's word, at the confirmation step or at the name.
// Turned off by the administrator (HIL-1303), the page is one plate, beside a
// removal that stands, and an open window closes when it turns off. Every
// window is held with its body locked while its request is out (HIL-1763).
import {
  ActionError,
  bindCodeSendProgress,
  CODE_SEND_PURPOSE_STEP_UP,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENT,
  createSignal,
  ScopeManager,
  SIGNAL_CODE_SEND_PROGRESS,
  type ActionHandle,
  type ActionLifecycle,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core/base'
import {
  enableAutoUnmount,
  flushPromises,
  mount,
  type VueWrapper,
} from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import { profileSectionFixture } from '../../../core/test/profile/profileSectionFixture.js'
import { hilosRouterKey } from '../hilosRouterKey.js'
import HilosProfileSecurityPage from './HilosProfileSecurityPage.vue'

// The modals teleport to <body>, so assertions query the document.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  tellSend({ state: null })
})

enableAutoUnmount(afterEach)

/** A section with no app connected yet, as the group frame carries it. */
const SECTION_OFF = {
  enabled: true,
  authenticators: [],
  backupCodesLeft: 0,
  backupCodesTotal: 0,
  required: false,
  resetWait: {
    days: 8,
    pendingDays: null,
    pendingFrom: null,
    defaultDays: 8,
    minDays: 1,
    maxDays: 30,
  },
  reset: null,
}

/** The section with one app connected, as the group frame carries it. */
const SECTION_ON = {
  ...SECTION_OFF,
  authenticators: [{ id: 1, label: 'Phone', createdAt: 0, lastUsedAt: null }],
  backupCodesLeft: 10,
  backupCodesTotal: 10,
}

/** The replies the server gives, by action name. */
const REPLIES: Record<string, unknown> = {
  hilos_step_up_start: { required: false, purpose: 'add an authenticator app' },
  profile_second_factor_enroll_start: {
    authenticatorId: 3,
    secret: 'JBSWY3DPEHPK3PXP',
    otpauthUri: 'otpauth://totp/Hilos:a@b.test?secret=JBSWY3DPEHPK3PXP',
  },
  profile_second_factor_enroll_confirm: { backupCodes: ['abcde-fghjk'] },
}

/**
 * A mounted page whose section says no app is connected, over an action
 * lifecycle that records every dispatch and answers from {@link REPLIES}.
 *
 * @param replies The server's answers by action name; one given as a promise
 *   is held until it settles.
 * @param section The section the group frame carries.
 */
function pageWorld(
  replies: Record<string, unknown> = REPLIES,
  section: object = SECTION_OFF,
): {
  dispatched: string[]
  wrapper: VueWrapper
  push: (next: object) => void
} {
  const dispatched: string[] = []
  const listeners: Array<(signal: ProjectSignal) => void> = []
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => undefined
    },
  } as unknown as HilosConnection
  const actions = {
    dispatch(action: string): ActionHandle {
      dispatched.push(action)
      const reply = replies[action]

      return {
        requestId: `req-${dispatched.length}`,
        loading: createSignal(false),
        done:
          reply instanceof Promise
            ? reply.then((settled: unknown) => ({ reply: settled }))
            : Promise.resolve({ reply }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  const scopes = new ScopeManager()
  const wrapper = mount(HilosProfileSecurityPage, {
    attachTo: document.body,
    global: {
      provide: {
        [hilosRouterKey as symbol]: profileSectionFixture(
          scopes,
          'hilos_profile_security',
        ),
      },
    },
    props: { context: { connection, scopes, actions } },
  })
  const push = (next: object): void => {
    for (const listener of listeners) {
      listener({
        kind: 'project',
        type: 'hilos_second_factor_state',
        data: next,
      } as unknown as ProjectSignal)
    }
  }
  push(section)

  return { dispatched, wrapper, push }
}

/** The element carrying a data-id, wherever the modal put it. */
function byId(id: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (found === null) {
    throw new Error(`no [data-id="${id}"] in the document`)
  }

  return found
}

/** Type into a field the way a person does. */
function typeInto(id: string, value: string): void {
  const field = byId(id) as HTMLInputElement
  field.value = value
  field.dispatchEvent(new Event('input'))
}

/** Send a form the way Enter does: past its buttons. */
function submit(id: string): void {
  byId(id).dispatchEvent(new Event('submit', { cancelable: true }))
}

// The fieldset the modal stands its body in; a request in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

/** The window's Cancel, Close or Not now: the plain button of its footer. */
function cancelButton(): HTMLButtonElement {
  const found = document.querySelector<HTMLButtonElement>(
    '[data-id="modal"] .modal-footer .btn-secondary',
  )
  if (found === null) {
    throw new Error('no Cancel in the open window')
  }

  return found
}

/**
 * Hold the next reply to an action in flight until the test refuses it with the
 * server's sentence.
 *
 * @param replies The world's answers, read at each dispatch.
 * @param action The action whose reply is held.
 */
function holdReply(
  replies: Record<string, unknown>,
  action: string,
): { refuse(reason: string): void } {
  let refuse!: (reason: string) => void
  replies[action] = new Promise((_resolve, reject) => {
    refuse = (reason) => reject(new ActionError(action, 'fail', reason))
  })

  return { refuse }
}

/**
 * Expect the open window held while its request is out: the body locked, Cancel
 * and the × off, and the ×, Cancel, Escape and the backdrop all leaving it open
 * with no Discard step.
 */
async function expectHeld(): Promise<void> {
  expect(bodyLock()?.disabled).toBe(true)
  expect(cancelButton().disabled).toBe(true)
  expect((byId('modal-close') as HTMLButtonElement).disabled).toBe(true)
  byId('modal-close').click()
  cancelButton().click()
  byId('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  byId('modal').click()
  await flushPromises()
  expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
  expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
}

/** Expect the open window free again: the body, Cancel and the × all live. */
function expectFreed(): void {
  expect(bodyLock()?.disabled).toBe(false)
  expect(cancelButton().disabled).toBe(false)
  expect((byId('modal-close') as HTMLButtonElement).disabled).toBe(false)
}

/**
 * Tell the session's send line as the server frames it, through the binding the
 * application boots with; the binding is let go once the frame is in, and a
 * null state takes the line away.
 *
 * @param line The frame's fields beside its message, which is none.
 */
function tellSend(line: Record<string, unknown>): void {
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

/** What the connect window's calm region says now. */
function calmText(): string | null {
  return byId('profile-2fa-enroll-live-polite').textContent
}

/**
 * The element itself, or else the first thing inside it, that matches the
 * selector: the root of a block is the likeliest place for a voice to return.
 *
 * @param element The block to look through, root included.
 * @param selector What a live voice looks like.
 */
function selfOrInside(element: Element, selector: string): Element | null {
  return element.matches(selector) ? element : element.querySelector(selector)
}

/** A confirmation that emailed its code, whose resend gate opens in a minute. */
const EMAIL_STEP = {
  required: true,
  purpose: 'add an authenticator app',
  method: 'email_code',
  destination: 'a@example.test',
  send: { sent: true, resendAt: Date.now() + 60_000, expiresAt: null },
}

/** Open "Add an app" and name the app. */
async function openEnrolment(wrapper: VueWrapper): Promise<void> {
  await wrapper.find('[data-id="profile-2fa-add"]').trigger('click')
  await flushPromises()
  await nextTick()
  typeInto('profile-2fa-enroll-label', 'Work phone')
}

describe('HilosProfileSecurityPage', () => {
  it('asks the server before the modal opens and starts at the name when no step is needed', async () => {
    const { dispatched, wrapper } = pageWorld()
    await nextTick()
    await wrapper.find('[data-id="profile-2fa-add"]').trigger('click')
    expect(dispatched).toEqual(['hilos_step_up_start'])
    await flushPromises()
    await nextTick()

    expect(byId('profile-2fa-enroll-label')).toBeTruthy()
    expect(document.querySelector('[data-id="step-up"]')).toBeNull()
  })

  it('opens at the confirmation step for the first app and moves to the name once confirmed', async () => {
    const { dispatched, wrapper } = pageWorld({
      ...REPLIES,
      hilos_step_up_start: {
        required: true,
        purpose: 'add an authenticator app',
        method: 'password',
      },
    })
    await nextTick()
    await wrapper.find('[data-id="profile-2fa-add"]').trigger('click')
    await flushPromises()
    await nextTick()
    expect(document.querySelector('.modal-title')?.textContent).toBe(
      "Confirm it's you",
    )
    expect(
      document.querySelector('[data-id="profile-2fa-enroll-label"]'),
    ).toBeNull()

    typeInto('step-up-password', 'secret')
    submit('profile-2fa-enroll')
    await flushPromises()
    await nextTick()

    expect(dispatched.at(-1)).toBe('hilos_step_up_confirm')
    expect(byId('profile-2fa-enroll-label')).toBeTruthy()
    expect(document.querySelector('.modal-title')?.textContent).toBe(
      'Add an authenticator app',
    )
  })

  it('keeps Confirm live-looking while the confirmation is out and sends it once', async () => {
    let answer!: (reply: unknown) => void
    const { dispatched, wrapper } = pageWorld({
      ...REPLIES,
      hilos_step_up_start: {
        required: true,
        purpose: 'add an authenticator app',
        method: 'password',
      },
      hilos_step_up_confirm: new Promise((resolve) => {
        answer = resolve
      }),
    })
    await nextTick()
    await wrapper.find('[data-id="profile-2fa-add"]').trigger('click')
    await flushPromises()
    await nextTick()
    const confirms = () =>
      dispatched.filter((action) => action === 'hilos_step_up_confirm')

    typeInto('step-up-password', 'secret')
    submit('profile-2fa-enroll')
    await flushPromises()
    const confirm = byId('profile-2fa-enroll-submit') as HTMLButtonElement
    expect(confirms()).toHaveLength(1)
    expect(confirm.getAttribute('aria-busy')).toBe('true')
    expect(confirm.getAttribute('aria-disabled')).toBe('true')
    expect(confirm.disabled).toBe(false)

    submit('profile-2fa-enroll')
    confirm.click()
    await flushPromises()
    expect(confirms()).toHaveLength(1)

    answer([])
    await flushPromises()
    await nextTick()
    expect(byId('profile-2fa-enroll-label')).toBeTruthy()
  })

  it('keeps Connect another live-looking while the next opening is out and asks once', async () => {
    let answer!: (reply: unknown) => void
    const replies: Record<string, unknown> = {
      ...REPLIES,
      profile_second_factor_enroll_confirm: {},
    }
    const { dispatched, wrapper } = pageWorld(replies)
    await nextTick()
    await openEnrolment(wrapper)
    submit('profile-2fa-enroll')
    await flushPromises()
    typeInto('profile-2fa-enroll-code', '123456')
    submit('profile-2fa-enroll')
    await flushPromises()
    await nextTick()
    const more = byId('profile-2fa-enroll-submit') as HTMLButtonElement
    expect(more.textContent?.trim()).toBe('Connect another')
    const openings = () =>
      dispatched.filter((action) => action === 'hilos_step_up_start')
    expect(openings()).toHaveLength(1)

    replies.hilos_step_up_start = new Promise((resolve) => {
      answer = resolve
    })
    more.click()
    await flushPromises()
    expect(openings()).toHaveLength(2)
    expect(more.getAttribute('aria-busy')).toBe('true')
    expect(more.getAttribute('aria-disabled')).toBe('true')
    expect(more.disabled).toBe(false)
    expect(more.classList.contains('disabled')).toBe(false)
    expect(more.querySelector('[data-id="loading-button-spinner"]')).toBeNull()

    more.click()
    submit('profile-2fa-enroll')
    await flushPromises()
    expect(openings()).toHaveLength(2)

    answer({ required: false, purpose: 'add an authenticator app' })
    await flushPromises()
    await nextTick()
    expect(byId('profile-2fa-enroll-label')).toBeTruthy()
  })

  it('asks a second app for a code from the connected one after the name, as before', async () => {
    const { dispatched, wrapper } = pageWorld(REPLIES, SECTION_ON)
    await nextTick()
    await openEnrolment(wrapper)
    submit('profile-2fa-enroll')
    await nextTick()

    expect(byId('profile-2fa-proof')).toBeTruthy()
    expect(dispatched).toEqual(['hilos_step_up_start'])
  })

  it('starts one enrolment however often Enter sends the form', async () => {
    const { dispatched, wrapper } = pageWorld()
    await nextTick()
    await openEnrolment(wrapper)

    submit('profile-2fa-enroll')
    submit('profile-2fa-enroll')
    await flushPromises()

    expect(
      dispatched.filter((action) => action.endsWith('_enroll_start')),
    ).toHaveLength(1)
  })

  it('puts the focus on the field of the step the modal moves to', async () => {
    const { wrapper } = pageWorld()
    await nextTick()
    await openEnrolment(wrapper)

    submit('profile-2fa-enroll')
    await flushPromises()
    await nextTick()

    expect(document.activeElement?.getAttribute('data-id')).toBe(
      'profile-2fa-enroll-code',
    )
  })

  it('asks before closing on codes not yet marked saved', async () => {
    const { dispatched, wrapper } = pageWorld()
    await nextTick()
    await openEnrolment(wrapper)
    submit('profile-2fa-enroll')
    await flushPromises()
    typeInto('profile-2fa-enroll-code', '123456')
    submit('profile-2fa-enroll')
    await flushPromises()
    expect(dispatched.at(-1)).toBe('profile_second_factor_enroll_confirm')

    // Enter on the codes step does not pass the "I have saved" tick either.
    submit('profile-2fa-enroll')
    await nextTick()
    expect(byId('backup-codes-list')).toBeTruthy()

    byId('modal-close').click()
    await nextTick()

    expect(byId('modal-confirm')).toBeTruthy()
    expect(byId('backup-codes-list')).toBeTruthy()
  })

  it('switches between the empty paragraph and the apps list when factors change', async () => {
    const { wrapper, push } = pageWorld(REPLIES, SECTION_OFF)
    await nextTick()

    const off = wrapper.find('[data-id="profile-2fa-off"]')
    expect(off.exists()).toBe(true)
    expect(off.element.tagName).toBe('P')
    expect(off.text().replace(/\s+/g, ' ').trim()).toBe(
      'Two-step verification is off. Connect an authenticator app to turn it on.',
    )
    expect(off.element.closest('.list-group')).toBeNull()
    expect(wrapper.find('[data-id="profile-2fa-apps"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="profile-2fa-codes-left"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="profile-2fa-add"]').exists()).toBe(true)

    push(SECTION_ON)
    await nextTick()

    expect(wrapper.find('[data-id="profile-2fa-off"]').exists()).toBe(false)
    const apps = wrapper.find('[data-id="profile-2fa-apps"]')
    expect(apps.exists()).toBe(true)
    expect(apps.element.tagName).toBe('UL')
    expect(wrapper.find('[data-id="profile-2fa-app-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="profile-2fa-codes-left"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="profile-2fa-add"]').exists()).toBe(true)

    push(SECTION_OFF)
    await nextTick()

    const offRestored = wrapper.find('[data-id="profile-2fa-off"]')
    expect(offRestored.exists()).toBe(true)
    expect(offRestored.element.tagName).toBe('P')
    expect(offRestored.text().replace(/\s+/g, ' ').trim()).toBe(
      'Two-step verification is off. Connect an authenticator app to turn it on.',
    )
    expect(offRestored.element.closest('.list-group')).toBeNull()
    expect(wrapper.find('[data-id="profile-2fa-apps"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="profile-2fa-codes-left"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="profile-2fa-add"]').exists()).toBe(true)
  })

  it("says the confirmation's line to its address through a region that outlives the step and falls silent at the name", async () => {
    const replies: Record<string, unknown> = {
      ...REPLIES,
      hilos_step_up_start: EMAIL_STEP,
    }
    const { wrapper } = pageWorld(replies)
    await nextTick()
    await wrapper.find('[data-id="profile-2fa-add"]').trigger('click')
    await flushPromises()
    await nextTick()
    const calm = byId('profile-2fa-enroll-live-polite')
    expect(calm.getAttribute('role')).toBe('status')
    expect(calm.getAttribute('aria-live')).toBe('polite')
    expect(calm.closest('[aria-modal="true"]')).not.toBeNull()
    expect(calmText()).toBe('')

    tellSend(confirmationLine('this', CODE_SEND_STATE_QUEUED))
    await flushPromises()
    expect(calmText()).toBe('Queued for sending…')
    tellSend(confirmationLine('this', CODE_SEND_STATE_SENT))
    await flushPromises()
    expect(byId('profile-2fa-enroll-live-polite')).toBe(calm)
    expect(calmText()).toBe('Sent to a@example.test')
    tellSend(
      confirmationLine('this', CODE_SEND_STATE_FAILED, {
        detail: 'mailbox unavailable',
      }),
    )
    await flushPromises()
    expect(calmText()).toBe('Could not send: mailbox unavailable')

    // The countdown and the line show on the row, which has no voice of its own.
    const row = byId('step-up-send')
    expect(row.textContent).toContain('Could not send: mailbox unavailable')
    expect(row.textContent).toContain('Send again in')
    expect(calmText()).not.toContain('Send again in')
    expect(
      selfOrInside(
        byId('step-up'),
        '[role="status"], [role="alert"], [aria-live]',
      ),
    ).toBeNull()

    // A refusal interrupts through its own region; the send line stays calm.
    const confirm = holdReply(replies, 'hilos_step_up_confirm')
    typeInto('step-up-code', '000000')
    submit('profile-2fa-enroll')
    await flushPromises()
    confirm.refuse('That code is not right')
    await flushPromises()
    expect(byId('profile-2fa-enroll-live').textContent).toContain(
      'That code is not right',
    )
    expect(calmText()).toBe('Could not send: mailbox unavailable')

    // The name follows, and the app's own code later on is typed, not sent.
    replies.hilos_step_up_confirm = []
    typeInto('step-up-code', '123456')
    submit('profile-2fa-enroll')
    await flushPromises()
    await nextTick()
    expect(byId('profile-2fa-enroll-label')).toBeTruthy()
    expect(byId('profile-2fa-enroll-live-polite')).toBe(calm)
    expect(calmText()).toBe('')
  })

  it.each([
    {
      method: 'sms_code',
      destination: '+15550001111',
      said: 'Sent to +15550001111',
    },
    { method: 'second_factor', destination: undefined, said: '' },
  ])(
    'says the confirmation line of a $method step only when a code was sent',
    async ({ method, destination, said }) => {
      const { wrapper } = pageWorld(
        {
          ...REPLIES,
          hilos_step_up_start: {
            required: true,
            purpose: 'add an authenticator app',
            method,
            destination,
          },
        },
        SECTION_ON,
      )
      await nextTick()
      await wrapper.find('[data-id="profile-2fa-add"]').trigger('click')
      await flushPromises()
      await nextTick()
      const calm = byId('profile-2fa-enroll-live-polite')
      expect(calmText()).toBe('')

      tellSend(confirmationLine('this', CODE_SEND_STATE_SENT))
      await flushPromises()
      expect(byId('profile-2fa-enroll-live-polite')).toBe(calm)
      expect(calmText()).toBe(said)
    },
  )

  it('leaves the line of a closed window behind and says the next attempt from a new region', async () => {
    const { wrapper } = pageWorld({
      ...REPLIES,
      hilos_step_up_start: EMAIL_STEP,
    })
    await nextTick()
    await wrapper.find('[data-id="profile-2fa-add"]').trigger('click')
    await flushPromises()
    await nextTick()
    const first = byId('profile-2fa-enroll-live-polite')
    tellSend(confirmationLine('closed', CODE_SEND_STATE_SENT))
    await flushPromises()
    expect(calmText()).toBe('Sent to a@example.test')

    cancelButton().click()
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()

    await wrapper.find('[data-id="profile-2fa-add"]').trigger('click')
    await flushPromises()
    await nextTick()
    expect(byId('step-up')).toBeTruthy()
    expect(byId('profile-2fa-enroll-live-polite')).not.toBe(first)
    expect(calmText()).toBe('')
    tellSend(confirmationLine('again', CODE_SEND_STATE_QUEUED))
    await flushPromises()
    expect(calmText()).toBe('Queued for sending…')
  })
})

describe('HilosProfileSecurityPage with a request out', () => {
  it('holds Add an app and locks the name while it is sent, and frees them after a refusal', async () => {
    const replies: Record<string, unknown> = { ...REPLIES }
    const { wrapper } = pageWorld(replies)
    await nextTick()
    await openEnrolment(wrapper)
    await nextTick()
    const field = byId('profile-2fa-enroll-label') as HTMLInputElement
    const body = bodyLock()
    expect(body?.contains(field)).toBe(true)
    expectFreed()
    const start = holdReply(replies, 'profile_second_factor_enroll_start')

    submit('profile-2fa-enroll')
    await flushPromises()
    await expectHeld()

    start.refuse('Two-step verification is not available right now')
    await flushPromises()
    expect(bodyLock()).toBe(body)
    expect(byId('profile-2fa-enroll-label')).toBe(field)
    expect(field.value).toBe('Work phone')
    expectFreed()
    expect(byId('hilos-action-error').textContent).toContain(
      'Two-step verification is not available right now',
    )

    replies.profile_second_factor_enroll_start =
      REPLIES.profile_second_factor_enroll_start
    submit('profile-2fa-enroll')
    await flushPromises()
    await nextTick()
    expect(byId('profile-2fa-enroll-code')).toBeTruthy()
  })

  it('holds Add an app and locks the password while its confirmation is out, and frees them after a refusal', async () => {
    const replies: Record<string, unknown> = {
      ...REPLIES,
      hilos_step_up_start: {
        required: true,
        purpose: 'add an authenticator app',
        method: 'password',
      },
    }
    const { wrapper } = pageWorld(replies)
    await nextTick()
    await wrapper.find('[data-id="profile-2fa-add"]').trigger('click')
    await flushPromises()
    await nextTick()
    typeInto('step-up-password', 'wrong')
    await nextTick()
    const field = byId('step-up-password') as HTMLInputElement
    const body = bodyLock()
    expect(body?.contains(field)).toBe(true)
    expectFreed()
    const confirm = holdReply(replies, 'hilos_step_up_confirm')

    submit('profile-2fa-enroll')
    await flushPromises()
    await expectHeld()

    confirm.refuse('That password is not right')
    await flushPromises()
    expect(bodyLock()).toBe(body)
    expect(byId('step-up-password')).toBe(field)
    expect(field.value).toBe('wrong')
    expectFreed()
    expect(byId('step-up-error').textContent).toContain(
      'That password is not right',
    )

    replies.hilos_step_up_confirm = []
    typeInto('step-up-password', 'secret')
    submit('profile-2fa-enroll')
    await flushPromises()
    await nextTick()
    expect(byId('profile-2fa-enroll-label')).toBeTruthy()
  })

  it.each([
    {
      window: 'Backup codes',
      open: 'profile-2fa-codes-show',
      field: 'profile-2fa-proof',
      value: '123456',
      action: 'profile_second_factor_codes_show',
      reply: { codes: [{ code: 'abcde-fghjk', used: false }] },
      send: 'profile-2fa-codes-submit',
      after: 'profile-2fa-codes-list',
    },
    {
      window: 'Remove',
      open: 'profile-2fa-remove-1',
      field: 'profile-2fa-proof',
      value: '123456',
      action: 'profile_second_factor_remove',
      reply: [],
      send: 'profile-2fa-remove-submit',
      after: null,
    },
    {
      window: 'Wait before removal',
      open: 'profile-2fa-wait-edit',
      field: 'profile-2fa-wait-days',
      value: '12',
      action: 'profile_second_factor_reset_wait_set',
      reply: [],
      send: 'profile-2fa-wait-save',
      after: null,
    },
    {
      window: 'Request removal',
      open: 'profile-2fa-reset-request',
      field: null,
      value: '',
      action: 'profile_second_factor_reset_request',
      reply: [],
      send: 'profile-2fa-reset-submit',
      after: null,
    },
  ])(
    'holds the $window window while its request is out, and frees it after a refusal',
    async ({ open, field, value, action, reply, send, after }) => {
      const replies: Record<string, unknown> = { ...REPLIES }
      const { dispatched } = pageWorld(replies, SECTION_ON)
      await nextTick()
      byId(open).click()
      await nextTick()
      if (field !== null) {
        typeInto(field, value)
        await nextTick()
      }
      const input = field === null ? null : (byId(field) as HTMLInputElement)
      const body = bodyLock()
      if (input !== null) {
        expect(body?.contains(input)).toBe(true)
      }
      expectFreed()
      const held = holdReply(replies, action)

      byId(send).click()
      await flushPromises()
      expect(dispatched.filter((sent) => sent === action)).toHaveLength(1)
      await expectHeld()

      held.refuse('The server is busy, try again')
      await flushPromises()
      expect(bodyLock()).toBe(body)
      if (field !== null) {
        expect(byId(field)).toBe(input)
        expect(input?.value).toBe(value)
      }
      expectFreed()
      expect(byId('hilos-action-error').textContent).toContain(
        'The server is busy, try again',
      )

      replies[action] = reply
      byId(send).click()
      await flushPromises()
      await nextTick()
      if (after === null) {
        expect(document.querySelector('[data-id="modal"]')).toBeNull()
      } else {
        expect(byId(after)).toBeTruthy()
      }
    },
  )
})

describe('HilosProfileSecurityPage turned off by the administrator', () => {
  it('is one plate saying the apps are kept, with no apps, codes, wait or buttons', async () => {
    const { wrapper } = pageWorld(REPLIES, { ...SECTION_ON, enabled: false })
    await nextTick()

    expect(wrapper.find('[data-id="hilos-2fa-turned-off"]').text()).toBe(
      'Your administrator turned two-step verification off. Your apps and backup codes are kept and will work again when it is turned back on.',
    )
    for (const id of [
      'profile-2fa-apps',
      'profile-2fa-off',
      'profile-2fa-codes-left',
      'profile-2fa-add',
      'profile-2fa-wait',
      'profile-2fa-reset-request',
    ]) {
      expect(wrapper.find(`[data-id="${id}"]`).exists()).toBe(false)
    }
  })

  it('says only the first sentence when no app is connected', async () => {
    const { wrapper } = pageWorld(REPLIES, { ...SECTION_OFF, enabled: false })
    await nextTick()

    expect(wrapper.find('[data-id="hilos-2fa-turned-off"]').text()).toBe(
      'Your administrator turned two-step verification off.',
    )
    for (const id of [
      'profile-2fa-apps',
      'profile-2fa-off',
      'profile-2fa-codes-left',
      'profile-2fa-add',
      'profile-2fa-wait',
      'profile-2fa-reset-request',
    ]) {
      expect(wrapper.find(`[data-id="${id}"]`).exists()).toBe(false)
    }
  })

  it('keeps a removal that stands beside the plate, with its date and Cancel', async () => {
    const { wrapper } = pageWorld(REPLIES, {
      ...SECTION_ON,
      enabled: false,
      reset: { requestedAt: 0, effectiveAt: 86_400_000 },
    })
    await nextTick()

    expect(wrapper.find('[data-id="hilos-2fa-turned-off"]').exists()).toBe(true)
    expect(
      wrapper.find('[data-id="profile-2fa-reset-pending"]').text(),
    ).toContain('Two-step verification will be removed on')
    expect(wrapper.find('[data-id="profile-2fa-reset-cancel"]').exists()).toBe(
      true,
    )
  })

  it('closes an open window when it turns off, and is the whole page again when it turns back on', async () => {
    const { wrapper, push } = pageWorld(REPLIES, SECTION_ON)
    await nextTick()
    await wrapper.find('[data-id="profile-2fa-codes-show"]').trigger('click')
    await nextTick()
    expect(byId('profile-2fa-proof')).toBeTruthy()

    push({ ...SECTION_ON, enabled: false })
    await nextTick()
    await nextTick()

    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(wrapper.find('[data-id="hilos-2fa-turned-off"]').exists()).toBe(true)

    push(SECTION_ON)
    await nextTick()
    expect(wrapper.find('[data-id="hilos-2fa-turned-off"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="profile-2fa-apps"]').exists()).toBe(true)
  })
})

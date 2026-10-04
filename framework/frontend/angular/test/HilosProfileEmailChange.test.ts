import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  bindProfileFlows,
  createHilosProfileEmailChangeFlow,
  profileFlowsSchema,
  SIGNAL_PROFILE_FLOWS,
  type ActionLifecycle,
  type HilosConnection,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosProfileEmailChange } from '../src/profile/HilosProfileEmailChange.js'

/** A send gate that reopened long ago: another code may be asked for at once. */
const PAST = 1_000_000_000_000
const SEND_REPLY = {
  sent: true,
  resendAt: PAST,
  expiresAt: 1_900_000_600_000,
}

const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
bindProfileFlows({
  on: (_event: string, listener: (signal: never) => void) => {
    listeners.push(
      listener as (signal: { type: string; data: unknown }) => void,
    )
    return () => undefined
  },
} as unknown as HilosConnection)

/**
 * The session's record of the email change at one step.
 *
 * @param step What has happened in the flow.
 * @param target The new address on the last step, null before it.
 */
function record(step: string, target: string | null = null) {
  return {
    operation: 'change_email',
    step,
    address: 'old@example.test',
    target,
  }
}

/**
 * Tell every tab the session's flows, as the frame carries them.
 *
 * @param flows The session's live flows, none for an ended one.
 */
function tell(flows: unknown[]): void {
  for (const listener of listeners)
    listener({
      type: SIGNAL_PROFILE_FLOWS,
      data: profileFlowsSchema.parse({ flows }),
    })
}

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

function setup() {
  tell([])
  // The frame the server tells before it answers the tab that sent the action.
  const frames: Record<string, unknown[]> = {}
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const answers: Record<string, unknown> = {
    hilos_step_up_start: {
      required: true,
      purpose: 'change your email',
      method: 'password',
    },
  }
  const flow = createHilosProfileEmailChangeFlow({
    actions: {
      dispatch: (action: string, payload?: unknown) => {
        dispatched.push({ action, payload })
        if (frames[action] !== undefined) tell(frames[action])
        return {
          done:
            typeof answers[action] === 'string'
              ? Promise.reject(new Error(answers[action] as string))
              : Promise.resolve({ reply: answers[action] ?? [] }),
        }
      },
    } as unknown as ActionLifecycle,
  })
  const fixture = TestBed.createComponent(HilosProfileEmailChange)
  fixture.componentRef.setInput('flow', flow)
  fixture.detectChanges()

  const node = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)!

  return { flow, answers, frames, dispatched, fixture, node }
}

describe('HilosProfileEmailChange', () => {
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, dispatched, fixture, node } = setup()
    await flow.open('old@example.test')
    await settle(fixture)

    const form = node('profile-email-step-up')
    const confirm = node('profile-email-step-up-confirm')

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

  it('draws the send block on the current-address code step and asks the flow for another code from it', async () => {
    const { flow, answers, frames, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    answers.profile_change_email_current_request = SEND_REPLY
    frames.profile_change_email_current_request = [record('current_sent')]
    await flow.open('old@example.test')
    await flow.submit()
    await settle(fixture)

    expect(node('profile-email-current-send')).not.toBeNull()
    const again = node('profile-email-current-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    again.click()
    await settle(fixture)
    expect(sendAgain).toHaveBeenCalledOnce()

    flow.dispose()
    fixture.destroy()
  })

  it('draws the send block on the new-address code step and asks the flow for another code from it', async () => {
    const { flow, answers, frames, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    answers.profile_change_email_new_request = SEND_REPLY
    frames.profile_change_email_new_request = [
      record('new_sent', 'new@example.test'),
    ]
    tell([record('current_proven')])
    await flow.open('old@example.test')
    flow.newEmail.set('new@example.test')
    await flow.submit()
    await settle(fixture)

    expect(node('profile-email-new-send')).not.toBeNull()
    const again = node('profile-email-new-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    again.click()
    await settle(fixture)
    expect(sendAgain).toHaveBeenCalledOnce()

    flow.dispose()
    fixture.destroy()
  })
})

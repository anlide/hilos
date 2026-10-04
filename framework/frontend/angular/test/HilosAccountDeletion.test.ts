import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createSignal,
  ScopeManager,
  type HilosConnection,
  type HilosSecondFactorContext,
  type ProjectSignal,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosAccountDeletion } from '../src/profile/HilosAccountDeletion.js'

/** A send gate that reopened long ago: another code may be asked for at once. */
const PAST = 1_000_000_000_000

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

function setup() {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const answers: Record<string, unknown> = {
    hilos_step_up_start: {
      required: true,
      purpose: 'delete your account',
      method: 'password',
    },
    hilos_account_deletion_open: {
      graceDays: 30,
      channel: 'email',
      destination: 'me@example.test',
    },
  }
  const dispatch = vi.fn((action: string, payload?: unknown) => {
    dispatched.push({ action, payload })
    return {
      requestId: action,
      loading: createSignal(false),
      done: Promise.resolve(
        answers[action] !== undefined ? { reply: answers[action] } : {},
      ),
    }
  })
  const connection = {
    on: (event: string, handler: (signal: ProjectSignal) => void) => {
      if (event === 'projectSignal') {
        listeners.add(handler)
        return () => listeners.delete(handler)
      }
      return () => undefined
    },
  } as unknown as HilosConnection
  const context = {
    connection,
    scopes: new ScopeManager(),
    actions: { dispatch },
  } as unknown as HilosSecondFactorContext

  const fixture = TestBed.createComponent(HilosAccountDeletion)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()

  const node = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)!

  return {
    answers,
    dispatch,
    dispatched,
    fixture,
    node,
    state(data: unknown) {
      for (const listener of listeners) {
        listener({
          kind: 'project',
          type: 'hilos_account_deletion_state',
          data,
        } as unknown as ProjectSignal)
      }
      fixture.detectChanges()
    },
  }
}

describe('HilosAccountDeletion', () => {
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { node, dispatched, fixture, state } = setup()
    state({ deletion: null })
    await settle(fixture)

    node('account-deletion-open').click()
    await settle(fixture)

    const form = node('account-deletion-step-up')
    const confirm = node('account-deletion-confirm')

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

    fixture.destroy()
  })

  it('draws the send block on the code step and asks the flow for another code from it', async () => {
    const { answers, node, dispatched, fixture, state } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'delete your account',
    }
    answers.hilos_account_deletion_code = {
      sent: true,
      resendAt: PAST,
      expiresAt: 1_900_000_600_000,
    }
    state({ deletion: null })
    await settle(fixture)
    node('account-deletion-open').click()
    await settle(fixture)
    node('account-deletion-continue').click()
    await settle(fixture)

    expect(node('account-deletion-send')).not.toBeNull()
    const again = node('account-deletion-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    again.click()
    await settle(fixture)
    // The flow is the window's own; another code is what its sendAgain() asks for.
    expect(
      dispatched.filter((d) => d.action === 'hilos_account_deletion_code'),
    ).toHaveLength(2)

    fixture.destroy()
  })
})

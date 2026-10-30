import { By } from '@angular/platform-browser'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  applyServerTime,
  createSignal,
  bindProfileFlows,
  ScopeManager,
  type HilosAccountDeletionFlow,
  type HilosConnection,
  type HilosRouter,
  type HilosSecondFactorContext,
  type ModalController,
  type ProjectSignal,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosModal } from '../src/HilosModal.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { HilosAccountDeletion } from '../src/profile/HilosAccountDeletion.js'

/** One day, in ms. */
const DAY = 86_400_000

/** A send gate that reopened long ago: another code may be asked for at once. */
const PAST = 1_000_000_000_000

afterEach(() => {
  vi.restoreAllMocks()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await flush(fixture)
  await fixture.whenStable()
  fixture.detectChanges()
}

function held<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: unknown) => void
  const promise = new Promise<T>((accept, refuse) => {
    resolve = accept
    reject = refuse
  })
  return { promise, resolve, reject }
}

async function flush(fixture: ComponentFixture<unknown>): Promise<void> {
  for (let tick = 0; tick < 10; tick += 1) await Promise.resolve()
  fixture.detectChanges()
}

function attemptClose(
  fixture: ComponentFixture<unknown>,
  node: (id: string) => HTMLElement,
  cancelId = 'account-deletion-cancel',
): void {
  node('modal-close').click()
  node(cancelId).click()
  node('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  node('modal').click()
  const modal = fixture.debugElement.query(By.directive(HilosModal))
    .componentInstance as unknown as { modal: ModalController }
  modal.modal.requestClose()
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
  const sendFlows = (flows: unknown[]) => {
    for (const listener of listeners) {
      listener({
        kind: 'project',
        type: 'hilos_profile_flows',
        data: { flows },
      } as unknown as ProjectSignal)
    }
  }
  const dispatch = vi.fn((action: string, payload?: unknown) => {
    dispatched.push({ action, payload })
    return {
      requestId: action,
      loading: createSignal(false),
      done: Promise.resolve(answers[action]).then((answer) => {
        if (action === 'hilos_account_deletion_code') {
          sendFlows([
            {
              operation: 'delete_account',
              step: 'code_sent',
              address: 'me@example.test',
              target: null,
            },
          ])
        }
        if (
          action === 'hilos_account_deletion_start' ||
          action === 'hilos_profile_flow_cancel'
        )
          sendFlows([])
        return answer !== undefined ? { reply: answer } : {}
      }),
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
  bindProfileFlows(connection)
  sendFlows([])
  const context = {
    connection,
    scopes: new ScopeManager(),
    actions: { dispatch },
  } as unknown as HilosSecondFactorContext

  const navigate = vi.fn()
  const router = {
    currentPath: createSignal('/profile/account'),
    navigate,
  } as unknown as HilosRouter
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
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
    flow: (
      fixture.componentInstance as unknown as {
        flow: () => HilosAccountDeletionFlow
      }
    ).flow(),
    navigate,
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
  it('bounds the countdown by the delivered request and keeps ticking through new state', async () => {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
    const requestedAt = 1_800_000_000_000
    vi.setSystemTime(requestedAt)
    applyServerTime(requestedAt - 300)
    const { fixture, node, state } = setup()
    try {
      state({ deletion: null })
      await settle(fixture)
      expect(vi.getTimerCount()).toBe(0)

      state({
        deletion: {
          requestedAt,
          effectiveAt: requestedAt + 30 * DAY,
        },
      })
      await settle(fixture)
      expect(node('account-deletion-scheduled').textContent).toContain(
        '30 days left',
      )
      expect(vi.getTimerCount()).toBe(1)

      vi.setSystemTime(requestedAt + DAY)
      await vi.advanceTimersByTimeAsync(60_000)
      await settle(fixture)
      expect(node('account-deletion-scheduled').textContent).toContain(
        '29 days left',
      )

      state({ deletion: null })
      await settle(fixture)
      expect(
        document.querySelector('[data-id="account-deletion-scheduled"]'),
      ).toBeNull()
      expect(
        document.querySelector('[data-id="account-deletion-open"]'),
      ).not.toBeNull()
      expect(vi.getTimerCount()).toBe(0)

      state({
        deletion: {
          requestedAt: Date.now(),
          effectiveAt: Date.now() + 7 * DAY,
        },
      })
      await settle(fixture)
      expect(node('account-deletion-scheduled').textContent).toContain(
        '7 days left',
      )
      expect(vi.getTimerCount()).toBe(1)
    } finally {
      fixture.destroy()
      const timersLeft = vi.getTimerCount()
      applyServerTime(Date.now())
      vi.useRealTimers()
      expect(timersLeft).toBe(0)
    }
  })

  it('starts the zone clock when a deletion appears', async () => {
    let now = 1_700_000_000_000
    vi.spyOn(Date, 'now').mockImplementation(() => now)
    const { fixture, node, state } = setup()
    state({ deletion: null })
    await settle(fixture)
    now += 50_000
    state({
      deletion: { requestedAt: now, effectiveAt: now + 30 * 86_400_000 },
    })
    await settle(fixture)
    expect(node('account-deletion-scheduled').textContent).toContain(
      '30 days left',
    )
    fixture.destroy()
  })

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

  it('captures clicks and native new-tab clicks on Your data while sending, then restores navigation on refusal', async () => {
    const { answers, fixture, flow, navigate, node, state } = setup()
    answers.hilos_step_up_start = { required: false }
    const response = held<unknown>()
    answers.hilos_account_deletion_code = response.promise
    state({ deletion: null })
    node('account-deletion-open').click()
    await settle(fixture)
    const link = node('account-deletion-data-link').querySelector('a')!
    const close = vi.spyOn(flow, 'close')
    const consumer = vi.fn()
    link.addEventListener('click', consumer)
    link.addEventListener('auxclick', consumer)
    node('account-deletion-continue').click()
    // The capture callback reads busy even before the next render updates ARIA.
    for (const [type, options] of [
      ['click', {}],
      ['click', { ctrlKey: true }],
      ['auxclick', { button: 1 }],
    ] as const) {
      const event = new MouseEvent(type, {
        bubbles: true,
        cancelable: true,
        ...options,
      })
      link.dispatchEvent(event)
      expect(event.defaultPrevented).toBe(true)
    }
    await flush(fixture)
    expect(flow.busy.get()).toBe(true)
    expect(link.getAttribute('aria-disabled')).toBe('true')
    expect(link.tabIndex).toBe(-1)
    expect(consumer).not.toHaveBeenCalled()
    expect(navigate).not.toHaveBeenCalled()
    expect(close).not.toHaveBeenCalled()
    expect(node('account-deletion-cancel').matches(':disabled')).toBe(true)
    expect(node('account-deletion-continue').matches(':disabled')).toBe(false)
    attemptClose(fixture, node)
    expect(flow.step.get()).toBe('explain')
    expect(node('modal-confirm')).toBeNull()

    response.reject(
      new ActionError('hilos_account_deletion_code', 'fail', 'Try again'),
    )
    await settle(fixture)
    expect(flow.busy.get()).toBe(false)
    expect(node('account-deletion-data-link').querySelector('a')).toBe(link)
    expect(link.hasAttribute('aria-disabled')).toBe(false)
    expect(link.hasAttribute('tabindex')).toBe(false)
    expect(node('account-deletion-error').textContent).toContain('Try again')
    expect(node('modal')).not.toBeNull()
    expect(close).not.toHaveBeenCalled()
    const aux = new MouseEvent('auxclick', {
      button: 1,
      bubbles: true,
      cancelable: true,
    })
    link.dispatchEvent(aux)
    expect(aux.defaultPrevented).toBe(false)
    expect(consumer).toHaveBeenCalledOnce()
    expect(close).not.toHaveBeenCalled()
    link.click()
    await settle(fixture)
    expect(navigate).toHaveBeenCalledOnce()
    expect(navigate).toHaveBeenCalledWith(link.getAttribute('href'))
    expect(close).toHaveBeenCalledOnce()
    expect(node('modal')).toBeNull()
    fixture.destroy()
  })

  it('cleans up capture listeners on step changes, reopens and destruction without adding them on busy changes', async () => {
    const added = vi.spyOn(HTMLElement.prototype, 'addEventListener')
    const { answers, fixture, flow, node, state } = setup()
    answers.hilos_step_up_start = { required: false }
    const response = held<unknown>()
    answers.hilos_account_deletion_code = response.promise
    state({ deletion: null })
    node('account-deletion-open').click()
    await settle(fixture)
    const container = node('account-deletion-data-link')
    const removed = vi.spyOn(container, 'removeEventListener')
    const captures = () =>
      added.mock.calls.filter(
        (call, index) =>
          added.mock.contexts[index] === container && call[2] === true,
      )
    expect(captures().map(([type]) => type)).toEqual(['click', 'auxclick'])
    node('account-deletion-continue').click()
    await flush(fixture)
    expect(captures()).toHaveLength(2)
    response.resolve(undefined)
    await settle(fixture)
    expect(flow.step.get()).toBe('code')
    expect(removed.mock.calls).toEqual(captures())

    flow.close()
    await settle(fixture)
    node('account-deletion-open').click()
    await settle(fixture)
    const reopened = node('account-deletion-data-link')
    expect(reopened).not.toBe(container)
    const newCaptures = added.mock.calls.filter(
      (call, index) =>
        added.mock.contexts[index] === reopened && call[2] === true,
    )
    expect(newCaptures.map(([type]) => type)).toEqual(['click', 'auxclick'])
    const removedOnDestroy = vi.spyOn(reopened, 'removeEventListener')
    fixture.destroy()
    expect(removedOnDestroy.mock.calls).toEqual(newCaptures)
    expect(removed.mock.calls).toEqual(captures())
  })

  it('locks step-up fields and exits during confirmation, keeps the credential on refusal and permits retry', async () => {
    const { answers, dispatched, fixture, flow, node, state } = setup()
    const response = held<unknown>()
    answers.hilos_step_up_confirm = response.promise
    state({ deletion: null })
    node('account-deletion-open').click()
    await settle(fixture)
    const form = node('account-deletion-step-up')
    const password = node('step-up-password') as HTMLInputElement
    password.value = 'secret'
    password.dispatchEvent(new Event('input', { bubbles: true }))
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await flush(fixture)
    expect(flow.busy.get()).toBe(true)
    expect(password.matches(':disabled')).toBe(true)
    expect(node('account-deletion-cancel').matches(':disabled')).toBe(true)
    expect(node('account-deletion-confirm').matches(':disabled')).toBe(false)
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    attemptClose(fixture, node)
    expect(flow.step.get()).toBe('step-up')
    expect(
      dispatched.filter((d) => d.action === 'hilos_step_up_confirm'),
    ).toHaveLength(1)
    expect(node('step-up-password')).toBe(password)
    expect(password.value).toBe('secret')
    response.reject(
      new ActionError('hilos_step_up_confirm', 'fail', 'Wrong password'),
    )
    await settle(fixture)
    expect(flow.busy.get()).toBe(false)
    expect(password.matches(':disabled')).toBe(false)
    expect(password.value).toBe('secret')
    expect(node('account-deletion-live').textContent).toContain(
      'Wrong password',
    )
    expect(node('modal')).not.toBeNull()
    answers.hilos_step_up_confirm = {}
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle(fixture)
    expect(flow.step.get()).toBe('explain')
    expect(
      dispatched.filter((d) => d.action === 'hilos_step_up_confirm'),
    ).toHaveLength(2)
    fixture.destroy()
  })

  it('preserves a submitted code through refusal and locks Close while keeping the account', async () => {
    const { answers, fixture, flow, node, state } = setup()
    answers.hilos_step_up_start = { required: false }
    state({ deletion: null })
    node('account-deletion-open').click()
    await settle(fixture)
    node('account-deletion-continue').click()
    await settle(fixture)
    const code = node('account-deletion-code') as HTMLInputElement
    const form = code.closest('form')!
    code.value = '123456'
    code.dispatchEvent(new Event('input', { bubbles: true }))
    const response = held<unknown>()
    answers.hilos_account_deletion_start = response.promise
    node('account-deletion-start').click()
    await flush(fixture)
    expect(flow.busy.get()).toBe(true)
    expect(code.matches(':disabled')).toBe(true)
    expect(code.value).toBe('123456')
    expect(node('account-deletion-code').closest('form')).toBe(form)
    attemptClose(fixture, node)
    expect(flow.step.get()).toBe('code')
    response.reject(
      new ActionError('hilos_account_deletion_start', 'fail', 'Invalid code'),
    )
    await settle(fixture)
    expect(flow.busy.get()).toBe(false)
    expect(code.matches(':disabled')).toBe(false)
    expect(code.value).toBe('123456')
    expect(node('account-deletion-code').closest('form')).toBe(form)
    expect(node('account-deletion-error').textContent).toContain('Invalid code')
    expect(node('modal')).not.toBeNull()
    expect(node('modal-confirm')).toBeNull()

    const success = held<unknown>()
    answers.hilos_account_deletion_start = success.promise
    node('account-deletion-start').click()
    await flush(fixture)
    const requestedAt = Date.now()
    state({ deletion: { requestedAt, effectiveAt: requestedAt + 30 * DAY } })
    success.resolve(undefined)
    await settle(fixture)
    expect(flow.step.get()).toBe('in-progress')
    const progress = node('account-deletion-modal')
    const keep = held<unknown>()
    answers.hilos_account_deletion_cancel = keep.promise
    node('account-deletion-keep').click()
    await flush(fixture)
    expect(flow.busy.get()).toBe(true)
    expect(node('account-deletion-close').matches(':disabled')).toBe(true)
    expect(node('account-deletion-keep').matches(':disabled')).toBe(false)
    attemptClose(fixture, node, 'account-deletion-close')
    expect(flow.step.get()).toBe('in-progress')
    keep.reject(
      new ActionError('hilos_account_deletion_cancel', 'fail', 'Try again'),
    )
    await settle(fixture)
    expect(node('account-deletion-close').matches(':disabled')).toBe(false)
    expect(node('account-deletion-modal')).toBe(progress)
    expect(node('account-deletion-error').textContent).toContain('Try again')
    answers.hilos_account_deletion_cancel = undefined
    node('account-deletion-keep').click()
    await settle(fixture)
    expect(node('modal')).toBeNull()
    fixture.destroy()
  })
})

import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  applyServerTime,
  createSignal,
  bindProfileFlows,
  ScopeManager,
  type HilosConnection,
  type HilosRouter,
  type HilosSecondFactorContext,
  type ProjectSignal,
} from '@hilos/core'
import { HilosAccountDeletion } from '../src/profile/HilosAccountDeletion.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** One day, in ms. */
const DAY = 86_400_000

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

/** The answer to a code request: the code is out, and another may follow at once. */
const SENT = {
  sent: true,
  resendAt: Date.now() - 1_000,
  expiresAt: Date.now() + 600_000,
}

function deferredReply() {
  let resolve!: (reply?: unknown) => void
  let reject!: (error: unknown) => void
  const done = new Promise<{ reply?: unknown }>((yes, no) => {
    resolve = (reply) => yes({ reply })
    reject = no
  })
  return { done, resolve, reject }
}

function setup() {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const pending: Record<string, ReturnType<typeof deferredReply>> = {}
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
    hilos_account_deletion_code: SENT,
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
    const done =
      pending[action]?.done ??
      Promise.resolve(
        answers[action] !== undefined ? { reply: answers[action] } : {},
      )
    return {
      requestId: action,
      loading: createSignal(false),
      done: done.then((result) => {
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
        return result
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
  const router = {
    currentPath: createSignal('/profile'),
    navigate: vi.fn(),
  } as unknown as HilosRouter

  const { unmount } = render(
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosAccountDeletion context={context} />
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  const node = (id: string) =>
    document.querySelector(`[data-id="${id}"]`) as HTMLElement

  return {
    answers,
    dispatch,
    dispatched,
    node,
    unmount,
    router,
    hold(action: string) {
      const reply = deferredReply()
      pending[action] = reply
      return reply
    },
    state(data: unknown) {
      act(() => {
        for (const listener of listeners) {
          listener({
            kind: 'project',
            type: 'hilos_account_deletion_state',
            data,
          } as unknown as ProjectSignal)
        }
      })
    },
  }
}

describe('HilosAccountDeletion', () => {
  it('locks step-up and every exit until confirmation settles and keeps the credential after refusal', async () => {
    const { node, dispatched, state, hold } = setup()
    state({ deletion: null })
    await act(async () => {
      fireEvent.click(node('account-deletion-open'))
    })
    const field = node('step-up-password') as HTMLInputElement
    const form = node('account-deletion-step-up')
    const live = node('account-deletion-live')
    fireEvent.change(field, { target: { value: 'secret' } })
    const pending = hold('hilos_step_up_confirm')
    await act(async () => {
      fireEvent.submit(form)
    })
    expect(field.matches(':disabled')).toBe(true)
    expect(node('account-deletion-cancel').matches(':disabled')).toBe(true)
    expect(node('modal-close').matches(':disabled')).toBe(true)
    expect(
      node('account-deletion-confirm').closest('fieldset:disabled'),
    ).toBeNull()
    await act(async () => {
      fireEvent.click(node('account-deletion-confirm'))
      fireEvent.submit(form)
      fireEvent.click(node('account-deletion-cancel'))
      fireEvent.click(node('modal-close'))
      fireEvent.keyDown(node('modal'), { key: 'Escape' })
      fireEvent.click(node('modal'))
    })
    expect(node('step-up-password')).toBe(field)
    expect(
      dispatched.filter((d) => d.action === 'hilos_step_up_confirm'),
    ).toHaveLength(1)
    await act(async () => {
      pending.reject(
        new ActionError(
          'hilos_step_up_confirm',
          'fail',
          'Password was refused',
        ),
      )
    })
    expect(node('step-up-password')).toBe(field)
    expect(field.value).toBe('secret')
    expect(field.matches(':disabled')).toBe(false)
    expect(node('account-deletion-cancel').matches(':disabled')).toBe(false)
    expect(node('modal-close').matches(':disabled')).toBe(false)
    expect(node('account-deletion-live')).toBe(live)
    expect(live.textContent).toBe('Password was refused')
    expect(node('step-up-error').textContent).toContain('Password was refused')
    const retry = hold('hilos_step_up_confirm')
    await act(async () => {
      fireEvent.click(node('account-deletion-confirm'))
    })
    await act(async () => {
      retry.resolve()
    })
    expect(node('account-deletion-continue')).not.toBeNull()
    await act(async () => {
      fireEvent.click(node('account-deletion-cancel'))
    })
    expect(node('modal')).toBeNull()
  })

  it.each(['click', 'auxclick'])(
    'captures Your data %s before the link while busy and restores idle navigation after refusal',
    async (eventType) => {
      const { answers, node, state, router, dispatched, hold } = setup()
      answers.hilos_step_up_start = {
        required: false,
        purpose: 'delete your account',
      }
      state({ deletion: null })
      await act(async () => {
        fireEvent.click(node('account-deletion-open'))
      })
      const dialog = node('modal')
      const wrapper = node('account-deletion-data-link')
      const link = wrapper.querySelector('a')!
      const target = vi.fn()
      link.addEventListener(eventType, target)
      const pending = hold('hilos_account_deletion_code')
      await act(async () => {
        fireEvent.click(node('account-deletion-continue'))
      })
      expect(link.getAttribute('aria-disabled')).toBe('true')
      expect(link.tabIndex).toBe(-1)
      expect(node('account-deletion-cancel').matches(':disabled')).toBe(true)
      expect(node('modal-close').matches(':disabled')).toBe(true)
      expect(
        node('account-deletion-continue').closest('fieldset:disabled'),
      ).toBeNull()
      const event = new MouseEvent(eventType, {
        bubbles: true,
        cancelable: true,
        button: eventType === 'auxclick' ? 1 : 0,
      })
      await act(async () => {
        fireEvent(link, event)
        fireEvent.click(node('account-deletion-cancel'))
        fireEvent.click(node('modal-close'))
        fireEvent.keyDown(dialog, { key: 'Escape' })
        fireEvent.click(dialog)
        fireEvent.click(node('account-deletion-continue'))
      })
      expect(event.defaultPrevented).toBe(true)
      expect(target).not.toHaveBeenCalled()
      expect(router.navigate).not.toHaveBeenCalled()
      expect(node('modal')).toBe(dialog)
      expect(node('account-deletion-data-link')).toBe(wrapper)
      expect(
        dispatched.filter((d) => d.action === 'hilos_account_deletion_code'),
      ).toHaveLength(1)
      expect(
        dispatched.some((d) => d.action === 'hilos_profile_flow_cancel'),
      ).toBe(false)
      await act(async () => {
        pending.reject(
          new ActionError(
            'hilos_account_deletion_code',
            'fail',
            'Code could not be sent',
          ),
        )
      })
      expect(node('account-deletion-data-link')).toBe(wrapper)
      expect(wrapper.querySelector('a')).toBe(link)
      expect(link.hasAttribute('aria-disabled')).toBe(false)
      expect(link.hasAttribute('tabindex')).toBe(false)
      expect(node('account-deletion-cancel').matches(':disabled')).toBe(false)
      expect(node('modal-close').matches(':disabled')).toBe(false)
      expect(node('account-deletion-error').textContent).toContain(
        'Code could not be sent',
      )
      if (eventType === 'auxclick') {
        const idleEvent = new MouseEvent('auxclick', {
          bubbles: true,
          cancelable: true,
          button: 1,
        })
        fireEvent(link, idleEvent)
        expect(idleEvent.defaultPrevented).toBe(false)
        expect(target).toHaveBeenCalledOnce()
        expect(node('modal')).toBe(dialog)
      }
      await act(async () => {
        fireEvent.click(link)
      })
      expect(router.navigate).toHaveBeenCalledExactlyOnceWith('/profile/data')
      expect(node('modal')).toBeNull()
    },
  )

  it('keeps the deletion code mounted after refusal, then leaves the scheduled grace period unlocked', async () => {
    const { answers, node, dispatched, state, hold } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'delete your account',
    }
    state({ deletion: null })
    await act(async () => {
      fireEvent.click(node('account-deletion-open'))
    })
    await act(async () => {
      fireEvent.click(node('account-deletion-continue'))
    })
    const field = node('account-deletion-code') as HTMLInputElement
    const form = field.closest('form')!
    const live = node('account-deletion-live')
    const slot = node('account-deletion-error-slot')
    fireEvent.change(field, { target: { value: '123456' } })
    const pending = hold('hilos_account_deletion_start')
    await act(async () => {
      fireEvent.submit(form)
    })
    expect(field.matches(':disabled')).toBe(true)
    expect(node('account-deletion-cancel').matches(':disabled')).toBe(true)
    expect(node('modal-close').matches(':disabled')).toBe(true)
    expect(
      node('account-deletion-start').closest('fieldset:disabled'),
    ).toBeNull()
    await act(async () => {
      fireEvent.submit(form)
      fireEvent.click(node('account-deletion-start'))
      fireEvent.click(node('account-deletion-send-again'))
      fireEvent.click(node('account-deletion-cancel'))
      fireEvent.click(node('modal-close'))
      fireEvent.keyDown(node('modal'), { key: 'Escape' })
      fireEvent.click(node('modal'))
    })
    expect(node('account-deletion-code')).toBe(field)
    expect(
      dispatched.filter((d) => d.action === 'hilos_account_deletion_start'),
    ).toHaveLength(1)
    expect(
      dispatched.filter((d) => d.action === 'hilos_account_deletion_code'),
    ).toHaveLength(1)
    await act(async () => {
      pending.reject(
        new ActionError(
          'hilos_account_deletion_start',
          'fail',
          'The code was refused',
        ),
      )
    })
    expect(node('account-deletion-code')).toBe(field)
    expect(field.value).toBe('123456')
    expect(field.matches(':disabled')).toBe(false)
    expect(node('account-deletion-cancel').matches(':disabled')).toBe(false)
    expect(node('modal-close').matches(':disabled')).toBe(false)
    expect(node('account-deletion-error-slot')).toBe(slot)
    expect(node('account-deletion-error').textContent).toContain(
      'The code was refused',
    )
    expect(node('account-deletion-live')).toBe(live)
    expect(live.textContent).toBe('The code was refused')
    const retry = hold('hilos_account_deletion_start')
    await act(async () => {
      fireEvent.click(node('account-deletion-start'))
    })
    await act(async () => {
      retry.resolve()
    })
    state({
      deletion: { requestedAt: Date.now(), effectiveAt: Date.now() + 30 * DAY },
    })
    expect(node('account-deletion-close').matches(':disabled')).toBe(false)
    expect(node('account-deletion-keep').matches(':disabled')).toBe(false)
    expect(node('modal-close').matches(':disabled')).toBe(false)
    expect(node('account-deletion-scheduled')).not.toBeNull()
    await act(async () => {
      fireEvent.click(node('account-deletion-close'))
    })
    expect(node('modal')).toBeNull()
  })

  it('holds in-progress Close through Keep my account pending and refusal and closes on successful retry', async () => {
    const { node, state, hold, dispatched } = setup()
    state({
      deletion: { requestedAt: Date.now(), effectiveAt: Date.now() + 30 * DAY },
    })
    await act(async () => {
      fireEvent.click(node('account-deletion-manage'))
    })
    const dialog = node('modal')
    const slot = node('account-deletion-error-slot')
    const pending = hold('hilos_account_deletion_cancel')
    await act(async () => {
      fireEvent.click(node('account-deletion-keep'))
    })
    expect(node('account-deletion-close').matches(':disabled')).toBe(true)
    expect(node('modal-close').matches(':disabled')).toBe(true)
    expect(
      node('account-deletion-keep').closest('fieldset:disabled'),
    ).toBeNull()
    expect(node('account-deletion-keep').getAttribute('aria-busy')).toBe('true')
    await act(async () => {
      fireEvent.click(node('account-deletion-close'))
      fireEvent.click(node('modal-close'))
      fireEvent.keyDown(dialog, { key: 'Escape' })
      fireEvent.click(dialog)
      fireEvent.click(node('account-deletion-keep'))
    })
    expect(node('modal')).toBe(dialog)
    expect(
      dispatched.filter((d) => d.action === 'hilos_account_deletion_cancel'),
    ).toHaveLength(1)
    await act(async () => {
      pending.reject(
        new ActionError(
          'hilos_account_deletion_cancel',
          'fail',
          'Try keeping your account again',
        ),
      )
    })
    expect(node('modal')).toBe(dialog)
    expect(node('account-deletion-close').matches(':disabled')).toBe(false)
    expect(node('modal-close').matches(':disabled')).toBe(false)
    expect(node('account-deletion-error-slot')).toBe(slot)
    expect(node('account-deletion-error').textContent).toContain(
      'Try keeping your account again',
    )
    const retry = hold('hilos_account_deletion_cancel')
    await act(async () => {
      fireEvent.click(node('account-deletion-keep'))
    })
    await act(async () => {
      retry.resolve()
    })
    expect(node('modal')).toBeNull()
    expect(
      dispatched.filter((d) => d.action === 'hilos_account_deletion_cancel'),
    ).toHaveLength(2)
  })

  it('bounds the countdown by the delivered request and keeps ticking through new state', async () => {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
    const requestedAt = 1_800_000_000_000
    vi.setSystemTime(requestedAt)
    applyServerTime(requestedAt - 300)
    const { node, state, unmount } = setup()
    try {
      state({ deletion: null })
      expect(vi.getTimerCount()).toBe(0)

      state({
        deletion: {
          requestedAt,
          effectiveAt: requestedAt + 30 * DAY,
        },
      })
      expect(node('account-deletion-scheduled').textContent).toContain(
        '30 days left',
      )
      expect(vi.getTimerCount()).toBe(1)

      vi.setSystemTime(requestedAt + DAY)
      await act(async () => {
        await vi.advanceTimersByTimeAsync(60_000)
      })
      expect(node('account-deletion-scheduled').textContent).toContain(
        '29 days left',
      )

      state({ deletion: null })
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
      expect(node('account-deletion-scheduled').textContent).toContain(
        '7 days left',
      )
      expect(vi.getTimerCount()).toBe(1)
    } finally {
      unmount()
      const timersLeft = vi.getTimerCount()
      applyServerTime(Date.now())
      vi.useRealTimers()
      expect(timersLeft).toBe(0)
    }
  })

  it('starts the zone clock when a deletion appears', () => {
    let now = 1_700_000_000_000
    vi.spyOn(Date, 'now').mockImplementation(() => now)
    const { node, state } = setup()
    state({ deletion: null })
    now += 50_000
    state({
      deletion: { requestedAt: now, effectiveAt: now + 30 * 86_400_000 },
    })
    expect(node('account-deletion-scheduled').textContent).toContain(
      '30 days left',
    )
  })

  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { node, dispatched, state } = setup()
    state({ deletion: null })

    await act(async () => {
      fireEvent.click(node('account-deletion-open'))
    })

    const form = node('account-deletion-step-up')
    const confirm = node('account-deletion-confirm')

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

  it('draws the send block on the code step and asks for another code from it', async () => {
    const { answers, node, dispatched, state } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'delete your account',
    }
    state({ deletion: null })
    await act(async () => {
      fireEvent.click(node('account-deletion-open'))
    })
    await act(async () => {
      fireEvent.click(node('account-deletion-continue'))
    })

    expect(node('account-deletion-send')).not.toBeNull()
    fireEvent.change(node('account-deletion-code'), {
      target: { value: '123456' },
    })
    const again = node('account-deletion-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    await act(async () => {
      fireEvent.click(again)
    })
    expect(
      dispatched.filter((d) => d.action === 'hilos_account_deletion_code'),
    ).toHaveLength(2)
    expect((node('account-deletion-code') as HTMLInputElement).value).toBe('')
  })
})

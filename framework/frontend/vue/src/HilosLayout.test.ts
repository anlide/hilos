import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  bindImpersonation,
  bindSessionScope,
  hilosToasts,
  IMPERSONATION_ACTION_STOP,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  ScopeManager,
} from '@hilos/core'
import type {
  ActionErrorSignal,
  ActionLifecycleSource,
  ActionSuccessSignal,
  HilosConnection,
  ProjectSignal,
  ProtectedModeStatus,
} from '@hilos/core'

import HilosLayout from './HilosLayout.vue'

/** A node frozen for a restore: the shell turns into the maintenance surface. */
const FROZEN: ProtectedModeStatus = {
  active: true,
  operation: 'restore',
  title: 'Restoring a backup',
  message: 'The application will be back in a few minutes.',
  bannerMessage: undefined,
  acceptsPass: false,
  passIssued: false,
  passRejected: false,
}

/** The verification window seen from inside it: the protected-mode strip is up. */
const ADMITTED: ProtectedModeStatus = {
  ...FROZEN,
  active: false,
  title: undefined,
  message: undefined,
  acceptsPass: true,
  bannerMessage: 'The restore is being verified.',
}

/** A handshake naming Bob as the user and Ada as the administrator behind him. */
const TAKEOVER = {
  entities: {
    currentUser: { id: 2, name: 'Bob' },
    impersonatedBy: { id: 1, name: 'Ada' },
  },
}

/** The shell's own connection: only the states it reads, fixed for the case. */
function shellConnection(
  protectedMode: ProtectedModeStatus = PROTECTED_MODE_INACTIVE,
): HilosConnection {
  return {
    state: 'connected',
    protectedMode,
    rtStaleness: RT_STALENESS_FRESH,
    reconnectDragging: false,
    on: () => () => {},
  } as unknown as HilosConnection
}

/** A lifecycle source that records what was sent and replies on demand. */
class ReplyingSource implements ActionLifecycleSource {
  readonly sent: { action: string; data: unknown; requestId?: string }[] = []
  private readonly success: ((signal: ActionSuccessSignal) => void)[] = []
  private readonly error: ((signal: ActionErrorSignal) => void)[] = []

  sendAction(action: string, data: unknown, requestId?: string): boolean {
    this.sent.push({ action, data, requestId })

    return true
  }

  on(event: string, listener: (payload: never) => void): () => void {
    if (event === 'actionSuccess') {
      this.success.push(listener as (signal: ActionSuccessSignal) => void)
    }
    if (event === 'actionError') {
      this.error.push(listener as (signal: ActionErrorSignal) => void)
    }

    return () => {}
  }

  succeed(requestId: string | undefined): void {
    for (const listener of this.success) {
      listener({
        kind: 'actionSuccess',
        action: IMPERSONATION_ACTION_STOP,
        requestId,
        envelope: { type: 'action_success', data: {} },
      } as ActionSuccessSignal)
    }
  }

  refuse(requestId: string | undefined, reason: string): void {
    for (const listener of this.error) {
      listener({
        kind: 'actionError',
        action: IMPERSONATION_ACTION_STOP,
        reason,
        requestId,
        envelope: { type: 'action_error', data: {} },
      } as ActionErrorSignal)
    }
  }
}

/**
 * Bind the strip's store the way bootHilos does, over a session scope fed by
 * handshakes this harness emits.
 */
function bindSession() {
  const projectListeners: ((signal: ProjectSignal) => void)[] = []
  const handshakes = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        projectListeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {}
    },
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  bindSessionScope(handshakes, scopes)
  const source = new ReplyingSource()
  const unbind = bindImpersonation(scopes, new ActionLifecycle(source))

  return {
    source,
    unbind,
    handshake(payload: Record<string, unknown>): void {
      const signal = {
        kind: 'project',
        type: 'handshake_response',
        data: payload,
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of projectListeners) {
        listener(signal)
      }
    },
  }
}

function mountShell(connection: HilosConnection, banner?: string) {
  return mount(HilosLayout, {
    props: { connection },
    slots: {
      default: '<p data-id="page-body">Page</p>',
      ...(banner === undefined ? {} : { banner }),
    },
  })
}

describe('HilosLayout impersonation strip', () => {
  let unbind: (() => void) | undefined

  afterEach(() => {
    unbind?.()
    unbind = undefined
    hilosToasts.clear()
  })

  it('draws no strip for a plain session', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake({ entities: { currentUser: { id: 1, name: 'Ada' } } })

    const wrapper = mountShell(shellConnection())

    expect(wrapper.find('[data-id="impersonation-banner"]').exists()).toBe(
      false,
    )
  })

  it('names the user the session acts as while impersonated', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)

    const wrapper = mountShell(shellConnection())

    const strip = wrapper.find('[data-id="impersonation-banner"]')
    expect(strip.exists()).toBe(true)
    expect(strip.text()).toContain('You are impersonating')
    expect(strip.find('strong').text()).toBe('Bob')
    expect(strip.find('[data-id="impersonation-stop"]').text()).toBe('Stop')
  })

  it('stands between the protected-mode strip and the project strip', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)

    const wrapper = mountShell(
      shellConnection(ADMITTED),
      '<p data-id="test-banner">A trial notice</p>',
    )

    const region = wrapper.find('[data-id="app-banner"]')
    const order = Array.from(region.element.children).map((child) =>
      child.getAttribute('data-id'),
    )
    expect(order).toEqual([
      'protected-mode-banner',
      'impersonation-banner',
      'test-banner',
    ])
  })

  it('draws no strip under the maintenance surface', () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)

    const wrapper = mountShell(shellConnection(FROZEN))

    expect(wrapper.find('[data-id="impersonation-banner"]').exists()).toBe(
      false,
    )
  })

  it('sends Stop tracked and keeps it disabled until the reply settles', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const wrapper = mountShell(shellConnection())
    const stop = wrapper.find('[data-id="impersonation-stop"]')

    await stop.trigger('click')

    expect(session.source.sent).toHaveLength(1)
    expect(session.source.sent[0]?.action).toBe('hilos_impersonate_stop')
    expect(session.source.sent[0]?.data).toEqual({})
    expect(session.source.sent[0]?.requestId).toBeTruthy()
    expect(stop.attributes('disabled')).toBeDefined()

    session.source.succeed(session.source.sent[0]?.requestId)
    await flushPromises()

    expect(
      wrapper.find('[data-id="impersonation-stop"]').attributes('disabled'),
    ).toBeUndefined()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('leaves the strip standing on a refusal and says why in a toast', async () => {
    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const wrapper = mountShell(shellConnection())

    await wrapper.find('[data-id="impersonation-stop"]').trigger('click')
    session.source.refuse(
      session.source.sent[0]?.requestId,
      'Session is not impersonating',
    )
    await flushPromises()

    expect(wrapper.find('[data-id="impersonation-banner"]').exists()).toBe(true)
    expect(
      wrapper.find('[data-id="impersonation-stop"]').attributes('disabled'),
    ).toBeUndefined()
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'Session is not impersonating']])
  })

  it('adds no live region of its own when the strip goes up', () => {
    // Counted before the strip goes up: both shells read the one store, so the
    // plain one would grow the strip too once the takeover lands.
    const live = '[role="status"][aria-live="polite"]'
    const plain = bindSession()
    plain.handshake({ entities: { currentUser: { id: 1, name: 'Ada' } } })
    const down = mountShell(shellConnection())
    const liveWithout = down.element.querySelectorAll(live).length
    down.unmount()
    plain.unbind()

    const session = bindSession()
    unbind = session.unbind
    session.handshake(TAKEOVER)
    const up = mountShell(shellConnection())

    expect(up.find('[data-id="impersonation-banner"]').exists()).toBe(true)
    expect(up.element.querySelectorAll(live).length).toBe(liveWithout)
  })
})

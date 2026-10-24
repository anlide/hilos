import {
  ActionError,
  DAEMON_NODE_DATA,
  DAEMON_PROCESSES_REPORTED_DATA,
  HilosPages,
  createSignal,
  bindAdminAccess,
  ScopeManager,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosDaemonAgentsPage } from '../src/admin/daemon/HilosDaemonAgentsPage.js'
import { daemonHarness } from './helpers/hilosDaemon.js'

function harness(clustered = false, placement = 'node') {
  let resolve: (value: ActionResult) => void = () => {}
  let reject: (reason: unknown) => void = () => {}
  const dispatch = vi.fn((): ActionHandle => ({
    requestId: 'restart-1',
    loading: createSignal(false),
    done: new Promise((yes, no) => {
      resolve = yes
      reject = no
    }),
  }))
  const h = daemonHarness(HilosDaemonAgentsPage, HilosPages.DAEMON_AGENTS, {
    dispatch,
  } as unknown as ActionLifecycle)
  h.scope.data.set(DAEMON_NODE_DATA, {
    clustered,
    role: null,
    state: clustered ? 'leader' : null,
    silentSince: null,
  })
  h.scope.data.set(DAEMON_PROCESSES_REPORTED_DATA, true)
  const window = (restarting = false) =>
    h.window('hilosDaemonAgents', [
      {
        rowKey: 'agent:1',
        slots: {
          agent: {
            agentId: 'agent:1',
            restarting,
            workerIndex: 1,
            workerKind: 'regular',
            placement,
            idle: false,
            ownsRt: [{ collection: 'rooms', width: 'set' }],
            ownsDb: [{ collection: 'users', width: 'rows' }],
            logStream: 'agent-agent_1.log',
          },
        },
      },
    ])
  window()
  return {
    ...h,
    dispatch,
    window,
    success: () => resolve({}),
    fail: () =>
      reject(
        new ActionError(
          'hilos_daemon_agent_restart',
          'fail',
          'Restart refused',
        ),
      ),
  }
}
function open(h: ReturnType<typeof harness>): void {
  h.el('hilos-daemon-agent-restart-agent:1')!.focus()
  h.el('hilos-daemon-agent-restart-agent:1')!.click()
  h.detect()
}
async function settle(h: ReturnType<typeof harness>): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
  h.detect()
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('HilosDaemonAgentsPage', () => {
  it.each([
    [false, 'node'],
    [true, 'policy'],
  ] as const)(
    'confirms on a focused dialog and shows the exact CLI for clustered=%s',
    (clustered, placement) => {
      const h = harness(clustered, placement)
      open(h)
      expect(h.el('hilos-daemon-restart-agent')?.textContent).toContain(
        'agent:1',
      )
      expect(h.el('hilos-daemon-restart-node')?.textContent).toContain('n1')
      expect(h.el('hilos-daemon-restart-cli')?.textContent?.trim()).toBe(
        'php cli.php agent:restart agent:1' + (clustered ? ' --node=n1' : ''),
      )
      expect(h.el('hilos-daemon-restart-policy') !== null).toBe(clustered)
      expect(document.activeElement?.getAttribute('role')).toBe('dialog')
      document.activeElement?.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
      )
      expect(h.dispatch).not.toHaveBeenCalled()
      h.el('hilos-daemon-restart-cancel')!.click()
      h.detect()
      expect(h.el('modal')).toBeNull()
      expect(document.activeElement).toBe(
        h.el('hilos-daemon-agent-restart-agent:1'),
      )
      h.fixture.destroy()
      expect(h.listeners()).toBe(0)
    },
  )

  it('blocks duplicate submit, Cancel, Escape and backdrop until the successful reply', async () => {
    const h = harness()
    open(h)
    const confirm = h.el('hilos-daemon-restart-confirm') as HTMLButtonElement
    confirm.click()
    h.detect()
    confirm.click()
    expect(h.dispatch).toHaveBeenCalledExactlyOnceWith(
      'hilos_daemon_agent_restart',
      { agentId: 'agent:1', nodeId: 'n1' },
    )
    expect(confirm.getAttribute('aria-disabled')).toBe('true')
    expect(
      (h.el('hilos-daemon-restart-cancel') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect((h.el('modal-close') as HTMLButtonElement).disabled).toBe(true)
    h.el('modal')!.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    h.detect()
    h.el('modal-close')!.click()
    h.detect()
    h.el('modal')!.click()
    h.detect()
    expect(h.el('modal')).not.toBeNull()
    h.success()
    await settle(h)
    expect(h.el('modal')).toBeNull()
    expect(h.el('hilos-daemon-agent-restarting-agent:1')).toBeNull()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('locks the confirmation for its own restart without turning it off, and turns it off at once for an independent reason', async () => {
    const h = harness()
    open(h)
    const confirm = () =>
      h.el('hilos-daemon-restart-confirm') as HTMLButtonElement
    expect(confirm().disabled).toBe(false)
    expect(confirm().hasAttribute('aria-disabled')).toBe(false)
    confirm().click()
    h.detect()
    expect(confirm().getAttribute('aria-disabled')).toBe('true')
    expect(confirm().disabled).toBe(false)
    confirm().click()
    h.detect()
    expect(h.dispatch).toHaveBeenCalledTimes(1)
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 1700000000,
    })
    h.detect()
    expect(confirm().disabled).toBe(true)
    expect(confirm().getAttribute('aria-disabled')).toBe('true')
    h.success()
    await settle(h)
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('keeps a refused confirmation open with the error inside, then allows Cancel', async () => {
    const h = harness()
    open(h)
    h.el('hilos-daemon-restart-confirm')!.click()
    h.detect()
    expect((h.el('modal-close') as HTMLButtonElement).disabled).toBe(true)
    expect(h.dispatch).toHaveBeenCalledTimes(1)
    h.fail()
    await settle(h)
    expect(h.el('modal')?.textContent).toContain('Restart refused')
    expect(
      (h.el('hilos-daemon-restart-confirm') as HTMLButtonElement).disabled,
    ).toBe(false)
    expect((h.el('modal-close') as HTMLButtonElement).disabled).toBe(false)
    h.el('hilos-daemon-restart-cancel')!.click()
    h.detect()
    expect(h.el('modal')).toBeNull()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('disables confirmation when the focused row leaves, and preserves its identity', () => {
    const h = harness()
    open(h)
    h.emit('tableViewportDelta', {
      data: {
        page: HilosPages.DAEMON_AGENTS,
        tableKey: 'hilosDaemonAgents',
        rowKey: 'agent:1',
        kind: 'row_removed',
        reason: 'left_set',
      },
    })
    h.detect()
    expect(
      (h.el('hilos-daemon-restart-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(h.el('hilos-daemon-restart-agent')?.textContent).toContain('agent:1')
    expect(
      h.el('hilos-daemon-restart-unavailable')?.classList.contains('invisible'),
    ).toBe(false)
    h.el('hilos-daemon-restart-confirm')!.click()
    expect(h.dispatch).not.toHaveBeenCalled()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it.each(['silent', 'frozen', 'refused', 'loading', 'restarting'])(
    'guards a %s table or target while confirmation is open',
    async (state) => {
      const h = harness()
      open(h)
      if (state === 'silent')
        h.scope.data.set(DAEMON_NODE_DATA, {
          clustered: true,
          role: null,
          state: 'silent',
          silentSince: 1700000000,
        })
      if (state === 'restarting') h.window(true)
      if (state === 'frozen')
        h.emit('tableViewportFrozen', {
          data: {
            page: HilosPages.DAEMON_AGENTS,
            tableKey: 'hilosDaemonAgents',
            since: 1700000000,
          },
        })
      if (state === 'refused')
        h.emit('tableWindowRefused', {
          data: {
            page: HilosPages.DAEMON_AGENTS,
            tableKey: 'hilosDaemonAgents',
            errorCode: 503,
          },
        })
      if (state === 'loading') {
        vi.useFakeTimers()
        try {
          const search = h.root.querySelector<HTMLInputElement>(
            '[data-id="hilos-table-search"]',
          )!
          search.value = 'other'
          search.dispatchEvent(new Event('input', { bubbles: true }))
          await vi.advanceTimersByTimeAsync(500)
        } finally {
          vi.useRealTimers()
        }
      }
      h.detect()
      expect(
        (h.el('hilos-daemon-restart-confirm') as HTMLButtonElement).disabled,
      ).toBe(true)
      h.fixture.destroy()
      expect(h.listeners()).toBe(0)
    },
  )

  it('draws ownership and server Restarting, rebinds node routes and blocks viewers', () => {
    const scopes = new ScopeManager()
    scopes.session.data.set('adminViewMode', true)
    const off = bindAdminAccess(scopes)
    try {
      const h = harness()
      expect(h.root.textContent).toContain('rooms · set')
      expect(h.root.textContent).toContain('users · rows')
      expect(
        (h.el('hilos-daemon-agent-restart-agent:1') as HTMLButtonElement)
          .disabled,
      ).toBe(true)
      h.window(true)
      expect(
        h.el('hilos-daemon-agent-restarting-agent:1')?.textContent,
      ).toContain('Restarting')
      h.route.set({
        page: HilosPages.DAEMON_AGENTS,
        params: { nodeId: 'n2' },
        admin: true,
      })
      h.detect()
      expect(h.unregister).toHaveBeenCalledTimes(1)
      h.window()
      expect(h.register.mock.calls.at(-1)?.[1].descriptor().filter).toEqual({
        node: 'n2',
      })
      expect(h.el('modal')).toBeNull()
      h.fixture.destroy()
      expect(h.listeners()).toBe(0)
    } finally {
      off()
    }
  })
})

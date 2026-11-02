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
import { bindTestTimeZone } from './timeZoneTestHarness.js'

const UNAVAILABLE_REASON = 'This node cannot currently vouch for a restart.'
const RESTARTING_REASON = 'This agent is already restarting.'

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
    name: null,
    role: null,
    state: clustered ? 'leader' : null,
    silentSince: null,
    clock: null,
  })
  h.scope.data.set(DAEMON_PROCESSES_REPORTED_DATA, true)
  const agent = (
    restarting = false,
    restartDisabledReason: string | null = restarting
      ? RESTARTING_REASON
      : null,
  ) => ({
    rowKey: 'agent:1',
    slots: {
      agent: {
        agentId: 'agent:1',
        restarting,
        restartDisabledReason,
        restartUnavailableReason: UNAVAILABLE_REASON,
        workerIndex: restarting ? null : 1,
        workerKind: restarting ? null : 'regular',
        placement,
        idle: false,
        ownsRt: [{ collection: 'rooms', width: 'set' }],
        ownsDb: [{ collection: 'users', width: 'rows' }],
        logStream: 'agent-agent_1.log',
      },
    },
  })
  const window = (restarting = false) =>
    h.window('hilosDaemonAgents', [agent(restarting)])
  window()
  return {
    ...h,
    dispatch,
    window,
    update(restartDisabledReason: string | null, restarting = false): void {
      h.emit('tableViewportDelta', {
        data: {
          page: HilosPages.DAEMON_AGENTS,
          tableKey: 'hilosDaemonAgents',
          kind: 'row_updated',
          rowKey: 'agent:1',
          row: agent(restarting, restartDisabledReason),
        },
      })
      h.detect()
    },
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

/** One restart opener and its explanation in the wide row or narrow card. */
function rowControls(
  h: ReturnType<typeof harness>,
  projection: 'table' | 'card' = 'table',
) {
  const selector =
    projection === 'table' ? 'table' : '[data-id="hilos-table-cards"]'
  const restart = h.root.querySelector<HTMLButtonElement>(
    `${selector} [data-id="hilos-daemon-agent-restart-agent:1"]`,
  )!
  const why = restart.parentElement!.querySelector<HTMLButtonElement>(
    '[data-id="hilos-action-reason"]',
  )!
  return { restart, why }
}

/** The real reason modal is relocated to the body by HilosActionReason. */
function explanation(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('HilosDaemonAgentsPage', () => {
  it('reformats the silent notice after a reader-zone change', () => {
    const timeZone = bindTestTimeZone('UTC')
    const h = harness(true)
    try {
      timeZone.receive('UTC')
      h.scope.data.set(DAEMON_NODE_DATA, {
        clustered: true,
        name: null,
        role: null,
        state: 'silent',
        silentSince: 1_700_000_000_000,
        clock: null,
      })
      h.detect()
      const line = h.el('hilos-daemon-agents-silent')!
      const initialLabel = line.textContent

      timeZone.receive('Pacific/Honolulu')
      h.detect()

      expect(line.textContent).not.toBe(initialLabel)
      expect(h.el('hilos-daemon-agents-silent')).toBe(line)
      expect(h.register).toHaveBeenCalledTimes(1)
      expect(h.unregister).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()
    } finally {
      h.fixture.destroy()
      vi.restoreAllMocks()
      timeZone.receive(null)
      timeZone.release()
      expect(timeZone.listenerCount()).toBe(0)
    }
  })

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

  it.each(['table', 'card'] as const)(
    'explains a server refusal from the %s without opening a restart or sending an action',
    (projection) => {
      const h = harness()
      expect(rowControls(h, projection).why.hidden).toBe(true)
      const reason = 'Restart is refused until <approval> is recorded.'
      h.update(reason)
      const { restart, why } = rowControls(h, projection)
      expect(restart.disabled).toBe(true)
      restart.click()
      h.detect()
      expect(h.el('modal')).toBeNull()
      expect(why.hidden).toBe(false)
      expect(why.type).toBe('button')
      expect(why.disabled).toBe(false)
      expect(why.getAttribute('aria-label')).toBe(
        'Why this action is unavailable',
      )
      why.focus()
      why.click()
      h.detect()

      const dialog = explanation('modal')!
      expect(dialog.getAttribute('role')).toBe('dialog')
      expect(dialog.getAttribute('aria-label')).toBe('Action unavailable')
      expect(document.activeElement).toBe(dialog)
      expect(explanation('hilos-action-reason-body')?.textContent?.trim()).toBe(
        reason,
      )
      expect(
        explanation('hilos-action-reason-body')?.querySelector('approval'),
      ).toBeNull()
      expect(h.el('hilos-daemon-restart-agent')).toBeNull()
      expect(h.connection.sendTableRowFocus).not.toHaveBeenCalled()
      dialog.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
      )
      const close = explanation('hilos-action-reason-close')!
      close.focus()
      close.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }),
      )
      expect(dialog.contains(document.activeElement)).toBe(true)
      dialog.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
      h.detect()
      expect(explanation('modal')).toBeNull()
      expect(document.activeElement).toBe(why)
      expect(h.dispatch).not.toHaveBeenCalled()
      h.fixture.destroy()
      expect(h.listeners()).toBe(0)
    },
  )

  it('keeps the opened reason snapshot while a live refusal changes, clears and returns', () => {
    const h = harness()
    const first = 'The node is waiting for permission to restart.'
    const next = 'The node is still waiting for permission.'
    h.update(first)
    const { why } = rowControls(h)
    why.click()
    h.detect()
    const dialog = explanation('modal')

    h.update(next)
    expect(why.title).toBe(next)
    expect(explanation('hilos-action-reason-body')?.textContent?.trim()).toBe(
      first,
    )
    h.update(null)
    for (const projection of ['table', 'card'] as const) {
      const controls = rowControls(h, projection)
      expect(controls.restart.disabled).toBe(false)
      expect(controls.why.hidden).toBe(true)
      expect(controls.why.hasAttribute('title')).toBe(false)
    }
    expect(explanation('modal')).toBe(dialog)
    expect(explanation('hilos-action-reason-body')?.textContent?.trim()).toBe(
      first,
    )
    explanation('hilos-action-reason-close')!.click()
    h.detect()
    h.update(next)
    expect(rowControls(h).restart.disabled).toBe(true)
    expect(why.hidden).toBe(false)
    why.click()
    h.detect()
    expect(explanation('hilos-action-reason-body')?.textContent?.trim()).toBe(
      next,
    )
    expect(h.dispatch).not.toHaveBeenCalled()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it.each(['missing', 'silent', 'frozen'])(
    'uses the delivered unavailable reason when the table is %s',
    (state) => {
      const h = harness()
      h.update('A stale refusal on the last reported row.')
      if (state === 'missing') h.scope.data.set(DAEMON_NODE_DATA, undefined)
      if (state === 'silent')
        h.scope.data.set(DAEMON_NODE_DATA, {
          clustered: true,
          name: null,
          role: null,
          state: 'silent',
          silentSince: 1700000000000,
          clock: null,
        })
      if (state === 'frozen')
        h.emit('tableViewportFrozen', {
          data: {
            page: HilosPages.DAEMON_AGENTS,
            tableKey: 'hilosDaemonAgents',
            since: 1700000000,
          },
        })
      h.detect()
      const { restart, why } = rowControls(h)
      expect(restart.disabled).toBe(true)
      expect(why.hidden).toBe(false)
      expect(why.title).toBe(UNAVAILABLE_REASON)
      restart.click()
      why.click()
      h.detect()
      expect(explanation('hilos-action-reason-body')?.textContent?.trim()).toBe(
        UNAVAILABLE_REASON,
      )
      expect(h.el('hilos-daemon-restart-agent')).toBeNull()
      expect(h.dispatch).not.toHaveBeenCalled()
      h.fixture.destroy()
      expect(h.listeners()).toBe(0)
    },
  )

  it.each(['unavailable', 'loading'])(
    'keeps the delivered reason and guards a retained target while the window is %s',
    async (state) => {
      const h = harness()
      const page = h.fixture.componentInstance
      const row = page['agents']().controller.rows.get()[0]!.row!
      if (state === 'unavailable')
        h.emit('tableWindowRefused', {
          data: {
            page: HilosPages.DAEMON_AGENTS,
            tableKey: 'hilosDaemonAgents',
            errorCode: 'unavailable',
          },
        })
      if (state === 'loading') {
        vi.useFakeTimers()
        try {
          const search = h.el('hilos-table-search') as HTMLInputElement
          search.value = 'other'
          search.dispatchEvent(new Event('input', { bubbles: true }))
          await vi.advanceTimersByTimeAsync(500)
        } finally {
          vi.useRealTimers()
        }
      }
      h.detect()
      // The table replaces its rows for these bodies. The page still owes
      // a delivered reason and an opening guard to any retained row target.
      expect(h.el('hilos-daemon-agent-restart-agent:1')).toBeNull()
      expect(page['restartReason'](row)).toBe(UNAVAILABLE_REASON)
      page['openRestart'](row)
      h.detect()
      expect(h.el('modal')).toBeNull()
      expect(h.dispatch).not.toHaveBeenCalled()
      h.fixture.destroy()
      expect(h.listeners()).toBe(0)
    },
  )

  it('removes the unavailable reason as soon as the heading returns live', () => {
    const h = harness()
    h.scope.data.set(DAEMON_NODE_DATA, undefined)
    h.detect()
    expect(rowControls(h).why.title).toBe(UNAVAILABLE_REASON)
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: false,
      name: null,
      role: null,
      state: null,
      silentSince: null,
      clock: null,
    })
    h.detect()
    expect(rowControls(h).why.hidden).toBe(true)
    expect(rowControls(h).restart.disabled).toBe(false)
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('explains a server restart mark and clears it when the live row returns', () => {
    const h = harness()
    h.window(true)
    const { restart, why } = rowControls(h)
    expect(restart.disabled).toBe(true)
    expect(why.hidden).toBe(false)
    why.click()
    h.detect()
    expect(explanation('hilos-action-reason-body')?.textContent?.trim()).toBe(
      RESTARTING_REASON,
    )
    expect(h.el('hilos-daemon-restart-agent')).toBeNull()
    expect(h.dispatch).not.toHaveBeenCalled()
    explanation('hilos-action-reason-close')!.click()
    h.detect()
    h.update(null)
    expect(h.el('hilos-daemon-agent-restarting-agent:1')).toBeNull()
    expect(rowControls(h).restart.disabled).toBe(false)
    expect(rowControls(h).why.hidden).toBe(true)
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('uses loading without a reason for its own request and preserves an independent restart reason', async () => {
    const h = harness()
    open(h)
    h.el('hilos-daemon-restart-confirm')!.click()
    h.detect()
    expect(
      h.el('hilos-daemon-restart-confirm')?.getAttribute('aria-busy'),
    ).toBe('true')
    expect(rowControls(h).restart.disabled).toBe(true)
    expect(rowControls(h).why.hidden).toBe(true)
    expect(h.el('hilos-daemon-agent-restarting-agent:1')).toBeNull()
    h.update(RESTARTING_REASON, true)
    expect(rowControls(h).why.hidden).toBe(false)
    expect(rowControls(h).why.title).toBe(RESTARTING_REASON)
    expect(h.dispatch).toHaveBeenCalledTimes(1)
    h.success()
    await settle(h)
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

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
      name: null,
      role: null,
      state: 'silent',
      silentSince: 1700000000000,
      clock: null,
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
          name: null,
          role: null,
          state: 'silent',
          silentSince: 1700000000000,
          clock: null,
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
      expect(rowControls(h).why.hidden).toBe(true)
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

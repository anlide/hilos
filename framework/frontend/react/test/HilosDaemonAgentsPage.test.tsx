import { openNodePage } from './helpers/hilosDaemonNode.js'
import {
  act,
  cleanup,
  fireEvent,
  render,
  waitFor,
  within,
} from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  bindAdminAccess,
  ScopeManager,
  pageIdentity,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  createSignal,
  HilosPages,
  type HilosConnection,
  type HilosRouter,
  type HilosDaemonNodeHeading,
  type PageRouteMatch,
} from '@hilos/core'
import { HilosDaemonAgentsPage } from '../src/admin/daemon/HilosDaemonAgentsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const releases: Array<() => void> = []
const UNAVAILABLE_REASON =
  'This node cannot currently confirm whether its agents can be restarted.'

function harness(showLogLinks?: boolean) {
  let resolve: (value: ActionResult) => void = () => {}
  let reject: (reason: unknown) => void = () => {}
  const loading = createSignal(false)
  const dispatch = vi.fn((): ActionHandle => ({
    requestId: 'restart-1',
    loading,
    done: new Promise((yes, no) => {
      resolve = yes
      reject = no
    }),
  }))
  const scopes = new ScopeManager()
  releases.push(bindAdminAccess(scopes))
  const scope = openNodePage(scopes, HilosPages.DAEMON_AGENTS)
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.DAEMON_AGENTS,
    params: { nodeId: 'n1' },
    admin: true,
  })
  const router = {
    currentRoute,
    currentPath: createSignal('/hilos/daemon/n1/agents'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (page: string) =>
      page === HilosPages.DAEMON ? '/hilos/daemon' : undefined,
    navigate: vi.fn(),
  } as unknown as HilosRouter
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const unregister = vi.fn()
  const register = vi.fn<HilosConnection['registerTableWindow']>()
  const focus = vi.fn()
  const connection = {
    on(event: string, callback: (payload: never) => void) {
      const group = listeners.get(event) ?? new Set()
      group.add(callback)
      listeners.set(event, group)
      return () => {
        group.delete(callback)
      }
    },
    registerTableWindow: register,
    unregisterTableWindow: unregister,
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn(),
    sendTableRendered: vi.fn(),
    sendTableRowFocus: focus,
  } as unknown as HilosConnection
  const view = render(
    <HilosRouterContext.Provider value={router}>
      <HilosDaemonAgentsPage
        context={{
          connection,
          scopes,
          actions: { dispatch } as unknown as ActionLifecycle,
        }}
        showLogLinks={showLogLinks}
      />
    </HilosRouterContext.Provider>,
  )
  return {
    view,
    dispatch,
    loading,
    viewer(value: boolean) {
      act(() => scopes.session.data.set('adminViewMode', value))
    },
    success: async () => {
      await act(async () => resolve({ message: 'Restarted' }))
    },
    fail: async () => {
      await act(async () =>
        reject(
          new ActionError(
            'hilos_daemon_agent_restart',
            'fail',
            'Restart refused',
          ),
        ),
      )
    },
    emit(event: string, data: unknown) {
      act(() => {
        for (const listener of listeners.get(event) ?? [])
          listener({ data } as never)
      })
    },
    currentRoute,
    register,
    unregister,
    listeners,
    focus,
    find: (id: string): HTMLElement | null =>
      document.querySelector(`[data-id="${id}"]`),
    heading(value: HilosDaemonNodeHeading | null) {
      act(() => scope.data.set('node', value))
    },
    reported(value: boolean) {
      act(() => scope.data.set('processesReported', value))
    },
    window(rows: Record<string, unknown>[]) {
      act(() => {
        for (const listener of listeners.get('tableWindow') ?? [])
          listener({
            data: {
              page: HilosPages.DAEMON_AGENTS,
              tableKey: 'hilosDaemonAgents',
              rows: rows.map((agent) => ({
                rowKey: String(agent.rowKey),
                slots: { agent },
              })),
              totalCount: rows.length,
              totalExact: true,
              limit: 25,
              firstAnchor: null,
              lastAnchor: null,
              rowsBefore: 0,
            },
          } as never)
      })
    },
  }
}

afterEach(() => {
  cleanup()
  for (const release of releases.splice(0)) release()
})
const LIVE: HilosDaemonNodeHeading = {
  clustered: true,
  name: null,
  role: null,
  state: 'leader',
  silentSince: null,
  clock: null,
}
const SILENT: HilosDaemonNodeHeading = {
  clustered: true,
  name: null,
  role: null,
  state: 'silent',
  silentSince: 1_700_000_000_000,
  clock: null,
}
function agent(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    rowKey: 'room:12',
    agentId: 'room:12',
    restarting: false,
    restartDisabledReason: null,
    restartUnavailableReason: UNAVAILABLE_REASON,
    workerIndex: 0,
    workerKind: 'regular',
    placement: 'node',
    idle: false,
    ownsRt: [{ collection: 'rooms', width: 'set' }],
    ownsDb: [{ collection: 'users', width: 'rows' }],
    logStream: 'room-12.log',
    ...overrides,
  }
}
function ready(placement = 'node') {
  const h = harness()
  h.heading(LIVE)
  h.reported(true)
  h.window([agent({ placement })])
  return h
}
function open(h: ReturnType<typeof harness>) {
  const opener = h.find('hilos-daemon-agent-restart-room:12')!
  opener.focus()
  fireEvent.click(opener)
  return opener
}
function button(
  h: ReturnType<typeof harness>,
  id = 'hilos-daemon-restart-confirm',
) {
  return h.find(id) as HTMLButtonElement
}

/** Deliver a value change without replacing the row's mounted controls. */
function updateAgent(
  h: ReturnType<typeof harness>,
  overrides: Record<string, unknown>,
): void {
  h.emit('tableViewportDelta', {
    page: HilosPages.DAEMON_AGENTS,
    tableKey: 'hilosDaemonAgents',
    kind: 'row_updated',
    rowKey: 'room:12',
    row: { rowKey: 'room:12', slots: { agent: agent(overrides) } },
  })
}

/** Read the same action surface in the table and the narrow-screen card. */
function actions(h: ReturnType<typeof harness>, projection = 'row') {
  return within(h.find(`hilos-table-${projection}-room:12`)!)
}

function reasonButton(h: ReturnType<typeof harness>, projection = 'row') {
  return actions(h, projection).queryByRole<HTMLButtonElement>('button', {
    name: 'Why this action is unavailable',
  })
}

describe('HilosDaemonAgentsPage', () => {
  it('distinguishes waiting, silent and reported empty rosters', () => {
    const h = harness()
    h.window([])
    expect(h.find('hilos-daemon-agents-empty-waiting')?.textContent).toContain(
      'not zero agents',
    )
    h.heading(SILENT)
    expect(h.find('hilos-daemon-agents-empty-silent')?.textContent).toContain(
      'never reported',
    )
    expect(h.find('hilos-daemon-node-state')?.textContent).toBe('Silent')
    expect(h.find('hilos-daemon-agents-silent')).toBeNull()
    h.reported(true)
    expect(h.find('hilos-daemon-agents-empty-none')?.textContent).toBe(
      'The node reports no agents',
    )
  })
  it('renders both ownership widths, workers, kind and logs; retains silent rows and updates live', () => {
    const h = ready()
    h.window([
      agent(),
      agent({
        rowKey: 'settings',
        agentId: 'settings',
        workerIndex: 2,
        workerKind: 'monopolistic',
        placement: 'leader',
        idle: true,
        ownsRt: [],
        ownsDb: [{ collection: 'settings', width: 'whole' }],
      }),
    ])
    const row = h.find('hilos-table-row-room:12')!
    expect(
      [...row.querySelectorAll('td')]
        .slice(0, 5)
        .map((cell) => cell.textContent),
    ).toEqual(['room:12', 'w0', 'Node replica', 'rooms · set', 'users · rows'])
    expect(
      [...h.find('hilos-table-row-settings')!.querySelectorAll('td')]
        .slice(0, 5)
        .map((cell) => cell.textContent),
    ).toEqual(['settings', 'm2', 'Leader-hosted · lazy', '—', 'settings'])
    expect(h.view.container.textContent).toContain('Owns in RT')
    expect(h.view.container.textContent).toContain('Owns in DB')
    expect(h.find('hilos-daemon-agent-log-room:12')?.getAttribute('href')).toBe(
      '/hilos/logs/view/n1/live/room-12.log',
    )
    expect(
      h
        .find('hilos-table-card-room:12')
        ?.querySelector('[data-id="hilos-daemon-agent-log-room:12"]')
        ?.getAttribute('href'),
    ).toBe('/hilos/logs/view/n1/live/room-12.log')
    h.heading(SILENT)
    expect(h.find('hilos-daemon-agents-silent')?.textContent).toContain(
      'last report',
    )
    expect(h.find('hilos-table-row-room:12')).toBe(row)
    h.heading({
      clustered: false,
      name: null,
      role: null,
      state: null,
      silentSince: null,
      clock: null,
    })
    h.window([agent({ idle: true, workerIndex: null, workerKind: null })])
    const updated = h.find('hilos-table-row-room:12')!
    expect(updated).not.toBe(row)
    expect(row.isConnected).toBe(false)
    expect(
      [...updated.querySelectorAll('td')]
        .slice(0, 3)
        .map((cell) => cell.textContent),
    ).toEqual(['room:12', '—', 'Lazy'])
    expect(h.find('hilos-daemon-node-solo')?.textContent).toBe(
      'Single installation',
    )
  })
  it.each([
    [false, 'policy', false],
    [true, 'node', false],
    [true, 'leader', false],
    [true, 'policy', true],
  ])(
    'focuses the dialog, names the target and gives the correct CLI (%s, %s)',
    (clustered, placement, warning) => {
      const h = ready(placement)
      h.heading({
        clustered,
        name: null,
        role: null,
        state: clustered ? 'leader' : null,
        silentSince: null,
        clock: null,
      })
      const opener = open(h)
      expect(document.activeElement).toBe(h.find('modal'))
      fireEvent.keyDown(h.find('modal')!, { key: 'Enter' })
      expect(h.dispatch).not.toHaveBeenCalled()
      expect(h.find('hilos-daemon-restart-agent')?.textContent).toBe('room:12')
      expect(h.find('hilos-daemon-restart-node')?.textContent).toBe('n1')
      expect(h.find('hilos-daemon-restart-cli')?.textContent).toBe(
        'php cli.php agent:restart room:12' + (clustered ? ' --node=n1' : ''),
      )
      expect(h.find('hilos-daemon-restart-policy') !== null).toBe(warning)
      fireEvent.click(button(h, 'hilos-daemon-restart-cancel'))
      expect(h.find('modal')).toBeNull()
      expect(document.activeElement).toBe(opener)
      expect(h.focus).toHaveBeenLastCalledWith(
        HilosPages.DAEMON_AGENTS,
        'hilosDaemonAgents',
        '',
      )
    },
  )
  it('dispatches the exact target once, blocks every close path while busy and closes on success', async () => {
    const h = ready()
    open(h)
    fireEvent.click(button(h))
    fireEvent.click(button(h))
    expect(h.dispatch).toHaveBeenCalledExactlyOnceWith(
      'hilos_daemon_agent_restart',
      { agentId: 'room:12', nodeId: 'n1' },
    )
    expect(button(h).getAttribute('aria-disabled')).toBe('true')
    expect(button(h, 'hilos-daemon-restart-cancel').disabled).toBe(true)
    expect(button(h, 'modal-close').disabled).toBe(true)
    expect(h.find('hilos-daemon-agent-restarting-room:12')).toBeNull()
    fireEvent.click(h.find('modal-close')!)
    fireEvent.keyDown(h.find('modal')!, { key: 'Escape' })
    fireEvent.click(h.find('modal')!)
    expect(h.find('modal')).not.toBeNull()
    act(() => h.loading.set(true))
    expect(button(h).getAttribute('aria-busy')).toBe('true')
    await h.success()
    expect(h.find('modal')).toBeNull()
    expect(h.focus).toHaveBeenLastCalledWith(
      HilosPages.DAEMON_AGENTS,
      'hilosDaemonAgents',
      '',
    )
  })
  it('disables confirmation while the core refuses another submit, including an independent unavailability', async () => {
    const h = ready()
    open(h)
    expect(button(h).disabled).toBe(false)
    expect(button(h).hasAttribute('aria-disabled')).toBe(false)
    fireEvent.click(button(h))
    expect(button(h).getAttribute('aria-disabled')).toBe('true')
    expect(button(h).disabled).toBe(true)
    fireEvent.click(button(h))
    expect(h.dispatch).toHaveBeenCalledTimes(1)
    h.heading(SILENT)
    expect(button(h).disabled).toBe(true)
    expect(button(h).getAttribute('aria-disabled')).toBe('true')
    await h.success()
  })
  it('keeps a refusal in the modal and clears it on a new confirmation', async () => {
    const h = ready()
    open(h)
    fireEvent.click(button(h))
    expect(button(h, 'modal-close').disabled).toBe(true)
    expect(h.dispatch).toHaveBeenCalledTimes(1)
    await h.fail()
    expect(h.find('modal')?.textContent).toContain('Restart refused')
    expect(button(h).disabled).toBe(false)
    expect(button(h, 'modal-close').disabled).toBe(false)
    fireEvent.click(button(h, 'hilos-daemon-restart-cancel'))
    open(h)
    expect(h.find('modal')?.textContent).not.toContain('Restart refused')
  })
  it('preserves the missing row identity and policy warning while blocking confirmation', async () => {
    const h = ready('policy')
    open(h)
    h.emit('tableViewportDelta', {
      page: HilosPages.DAEMON_AGENTS,
      tableKey: 'hilosDaemonAgents',
      rowKey: 'room:12',
      kind: 'row_removed',
      reason: 'left_set',
    })
    expect(h.find('hilos-daemon-restart-agent')?.textContent).toBe('room:12')
    expect(h.find('hilos-daemon-restart-policy')).not.toBeNull()
    expect(button(h).disabled).toBe(true)
    expect(
      h
        .find('hilos-daemon-restart-unavailable')
        ?.classList.contains('invisible'),
    ).toBe(false)
    fireEvent.click(button(h))
    expect(h.dispatch).not.toHaveBeenCalled()
  })
  it.each(['silent', 'unknown', 'frozen', 'refused', 'loading', 'restarting'])(
    'blocks an open confirmation on %s and leaves the identity readable',
    async (state) => {
      const h = ready()
      open(h)
      if (state === 'silent') h.heading(SILENT)
      if (state === 'unknown') h.heading(null)
      if (state === 'frozen')
        h.emit('tableViewportFrozen', {
          page: HilosPages.DAEMON_AGENTS,
          tableKey: 'hilosDaemonAgents',
          since: 1700000000,
        })
      if (state === 'refused')
        h.emit('tableWindowRefused', {
          page: HilosPages.DAEMON_AGENTS,
          tableKey: 'hilosDaemonAgents',
          errorCode: 'unavailable',
        })
      if (state === 'loading')
        fireEvent.change(h.find('hilos-table-search')!, {
          target: { value: 'next' },
        })
      if (state === 'restarting')
        h.window([
          agent({ restarting: true, workerIndex: null, workerKind: null }),
        ])
      await waitFor(() => expect(button(h).disabled).toBe(true))
      fireEvent.click(button(h))
      expect(h.dispatch).not.toHaveBeenCalled()
      expect(h.find('hilos-daemon-restart-agent')?.textContent).toBe('room:12')
    },
  )
  it('only shows a restarting mark from a real window and re-enables the live row', () => {
    const h = ready()
    const reason = 'This agent is already restarting.'
    h.window([
      agent({
        restarting: true,
        restartDisabledReason: reason,
        workerIndex: null,
        workerKind: null,
      }),
    ])
    expect(h.find('hilos-daemon-agent-restarting-room:12')?.textContent).toBe(
      'Restarting',
    )
    expect(button(h, 'hilos-daemon-agent-restart-room:12').disabled).toBe(true)
    fireEvent.click(reasonButton(h)!)
    expect(h.find('hilos-action-reason-body')?.textContent).toBe(reason)
    expect(h.find('hilos-daemon-restart-agent')).toBeNull()
    expect(h.dispatch).not.toHaveBeenCalled()
    fireEvent.click(h.find('hilos-action-reason-close')!)
    h.window([agent()])
    expect(h.find('hilos-daemon-agent-restarting-room:12')).toBeNull()
    expect(button(h, 'hilos-daemon-agent-restart-room:12').disabled).toBe(false)
    expect(reasonButton(h)).toBeNull()
  })
  it.each(['row', 'card'])(
    'explains a server refusal from the %s without requesting a restart',
    (projection) => {
      const h = ready()
      expect(reasonButton(h, projection)).toBeNull()
      const reason = 'Restart is refused until <approval> is recorded.'
      updateAgent(h, { restartDisabledReason: reason })
      const restart = actions(h, projection).getByRole<HTMLButtonElement>(
        'button',
        { name: 'Restart' },
      )
      expect(restart.disabled).toBe(true)
      fireEvent.click(restart)
      expect(h.find('modal')).toBeNull()

      const opener = reasonButton(h, projection)!
      expect(opener.type).toBe('button')
      expect(opener.disabled).toBe(false)
      opener.focus()
      fireEvent.click(opener)
      const dialog = h.view.getByRole('dialog', { name: 'Action unavailable' })
      expect(document.activeElement).toBe(dialog)
      expect(h.find('hilos-action-reason-body')?.textContent).toBe(reason)
      expect(
        h.find('hilos-action-reason-body')?.querySelector('approval'),
      ).toBeNull()
      fireEvent.keyDown(dialog, { key: 'Enter' })
      expect(h.find('hilos-daemon-restart-agent')).toBeNull()
      expect(h.dispatch).not.toHaveBeenCalled()

      const close = h.find('hilos-action-reason-close')!
      close.focus()
      fireEvent.keyDown(close, { key: 'Tab' })
      expect(dialog.contains(document.activeElement)).toBe(true)
      fireEvent.keyDown(dialog, { key: 'Escape' })
      expect(h.view.queryByRole('dialog')).toBeNull()
      expect(document.activeElement).toBe(opener)
    },
  )
  it('follows live refusal changes and keeps the opened reason snapshot', () => {
    const h = ready()
    const first = 'This agent is waiting for the leader.'
    const next = 'Restart is temporarily refused by the node.'
    updateAgent(h, { restartDisabledReason: first })
    fireEvent.click(reasonButton(h)!)
    updateAgent(h, { restartDisabledReason: next })
    expect(reasonButton(h)?.title).toBe(next)
    expect(h.find('hilos-action-reason-body')?.textContent).toBe(first)

    updateAgent(h, { restartDisabledReason: null })
    for (const projection of ['row', 'card']) {
      expect(reasonButton(h, projection)).toBeNull()
      expect(
        actions(h, projection).getByRole<HTMLButtonElement>('button', {
          name: 'Restart',
        }).disabled,
      ).toBe(false)
    }
    expect(h.find('hilos-action-reason-body')?.textContent).toBe(first)
    fireEvent.click(h.find('hilos-action-reason-close')!)

    updateAgent(h, { restartDisabledReason: next })
    expect(button(h, 'hilos-daemon-agent-restart-room:12').disabled).toBe(true)
    fireEvent.click(reasonButton(h)!)
    expect(h.find('hilos-action-reason-body')?.textContent).toBe(next)
    expect(h.dispatch).not.toHaveBeenCalled()
  })
  it.each(['unknown', 'silent', 'frozen'])(
    'uses the delivered unavailable reason when the table is %s',
    async (state) => {
      const h = ready()
      updateAgent(h, { restartDisabledReason: 'A stale row refusal.' })
      if (state === 'unknown') h.heading(null)
      if (state === 'silent') h.heading(SILENT)
      if (state === 'frozen')
        h.emit('tableViewportFrozen', {
          page: HilosPages.DAEMON_AGENTS,
          tableKey: 'hilosDaemonAgents',
          since: 1700000000,
        })
      await waitFor(() =>
        expect(reasonButton(h)?.title).toBe(UNAVAILABLE_REASON),
      )
      expect(button(h, 'hilos-daemon-agent-restart-room:12').disabled).toBe(
        true,
      )
      fireEvent.click(reasonButton(h)!)
      expect(h.find('hilos-action-reason-body')?.textContent).toBe(
        UNAVAILABLE_REASON,
      )
      expect(h.find('hilos-daemon-restart-agent')).toBeNull()
      expect(h.dispatch).not.toHaveBeenCalled()
    },
  )
  it('clears the unavailable explanation when a live heading returns', () => {
    const h = ready()
    h.heading(SILENT)
    expect(reasonButton(h)?.title).toBe(UNAVAILABLE_REASON)
    h.heading(LIVE)
    expect(reasonButton(h)).toBeNull()
    expect(button(h, 'hilos-daemon-agent-restart-room:12').disabled).toBe(false)
  })
  it('uses a spinner without inventing a reason during its own request and retains an independent server reason', async () => {
    const h = ready()
    open(h)
    fireEvent.click(button(h))
    expect(button(h).getAttribute('aria-busy')).toBe('true')
    expect(button(h, 'hilos-daemon-agent-restart-room:12').disabled).toBe(true)
    expect(reasonButton(h)).toBeNull()
    expect(h.find('hilos-daemon-agent-restarting-room:12')).toBeNull()

    const reason = 'Another restart has been reported by the node.'
    updateAgent(h, { restarting: true, restartDisabledReason: reason })
    expect(reasonButton(h)?.title).toBe(reason)
    expect(h.dispatch).toHaveBeenCalledTimes(1)
    await h.success()
  })
  it('follows a server refusal in an already open confirmation and permits submission only after it clears', async () => {
    const h = ready()
    open(h)
    expect(button(h).disabled).toBe(false)
    updateAgent(h, {
      restartDisabledReason: 'Restart is refused by the current node policy.',
    })
    expect(button(h).disabled).toBe(true)
    fireEvent.click(button(h))
    expect(h.dispatch).not.toHaveBeenCalled()
    expect(h.find('hilos-daemon-restart-agent')?.textContent).toBe('room:12')

    updateAgent(h, { restartDisabledReason: null })
    expect(button(h).disabled).toBe(false)
    fireEvent.click(button(h))
    expect(h.dispatch).toHaveBeenCalledExactlyOnceWith(
      'hilos_daemon_agent_restart',
      { agentId: 'room:12', nodeId: 'n1' },
    )
    await h.success()
  })
  it('blocks viewer opening and a confirmation already open when access becomes view-only', async () => {
    const h = ready()
    h.viewer(true)
    const opener = button(h, 'hilos-daemon-agent-restart-room:12')
    expect(opener.disabled).toBe(true)
    expect(opener.getAttribute('aria-describedby')).toBe(
      'hilos-view-mode-strip-text',
    )
    expect(reasonButton(h)).toBeNull()
    fireEvent.click(opener)
    expect(h.find('modal')).toBeNull()
    h.viewer(false)
    open(h)
    h.viewer(true)
    await waitFor(() => expect(button(h).disabled).toBe(true))
    fireEvent.click(button(h))
    expect(h.dispatch).not.toHaveBeenCalled()
  })
  it.each([false, true])(
    'releases focus and bindings on a node change, including pending=%s',
    async (pending) => {
      const h = ready()
      open(h)
      if (pending) fireEvent.click(button(h))
      const count = [...h.listeners.values()].reduce((n, g) => n + g.size, 0)
      act(() =>
        h.currentRoute.set({
          page: HilosPages.DAEMON_AGENTS,
          params: { nodeId: 'n2' },
          admin: true,
        }),
      )
      expect(h.find('modal')).toBeNull()
      expect(h.unregister).toHaveBeenCalledWith(
        'hilosDaemonAgents',
        h.register.mock.calls[0]?.[1],
      )
      expect(h.register).toHaveBeenCalledTimes(2)
      expect(h.focus).toHaveBeenLastCalledWith(
        HilosPages.DAEMON_AGENTS,
        'hilosDaemonAgents',
        '',
      )
      expect([...h.listeners.values()].reduce((n, g) => n + g.size, 0)).toBe(
        count,
      )
      h.window([agent()])
      expect(
        h.find('hilos-daemon-agent-log-room:12')?.getAttribute('href'),
      ).toBe('/hilos/logs/view/n2/live/room-12.log')
      open(h)
      expect(h.find('hilos-daemon-restart-node')?.textContent).toBe('n2')
      if (pending) {
        await h.success()
        expect(h.find('modal')).not.toBeNull()
      }
      h.view.unmount()
      expect(h.unregister).toHaveBeenCalledTimes(2)
      expect([...h.listeners.values()].every((g) => g.size === 0)).toBe(true)
      expect(h.focus).toHaveBeenLastCalledWith(
        HilosPages.DAEMON_AGENTS,
        'hilosDaemonAgents',
        '',
      )
    },
  )

  it('omits agent log links in rows and cards when showLogLinks is false while keeping restart lifecycle and retaining flag across node changes', async () => {
    const h = harness(false)
    h.heading({
      clustered: true,
      name: null,
      role: null,
      state: 'leader',
      silentSince: null,
      clock: null,
    })
    h.reported(true)
    h.window([agent()])

    const row = h.find('hilos-table-row-room:12')!
    expect(
      [...row.querySelectorAll('td')]
        .slice(0, 5)
        .map((cell) => cell.textContent),
    ).toEqual(['room:12', 'w0', 'Node replica', 'rooms · set', 'users · rows'])

    expect(h.find('hilos-daemon-agent-log-room:12')).toBeNull()
    const card = h.find('hilos-table-card-room:12')!
    expect(card).not.toBeNull()
    expect(
      card.querySelector('[data-id="hilos-daemon-agent-log-room:12"]'),
    ).toBeNull()

    const restartBtn = h.find('hilos-daemon-agent-restart-room:12')!
    expect(restartBtn).not.toBeNull()
    expect(
      card.querySelector('[data-id="hilos-daemon-agent-restart-room:12"]'),
    ).not.toBeNull()

    fireEvent.click(restartBtn)
    expect(h.find('hilos-daemon-restart-agent')?.textContent).toBe('room:12')
    fireEvent.click(button(h, 'hilos-daemon-restart-confirm'))
    expect(h.dispatch).toHaveBeenCalled()
    await h.success()
    expect(h.find('hilos-daemon-restart-agent')).toBeNull()

    act(() =>
      h.currentRoute.set({
        page: HilosPages.DAEMON_AGENTS,
        params: { nodeId: 'n2' },
        admin: true,
      }),
    )
    h.window([agent({ rowKey: 'room:99', agentId: 'room:99' })])
    expect(h.find('hilos-page-head-title')?.textContent).toBe('n2')
    expect(h.find('hilos-daemon-agent-log-room:99')).toBeNull()
    expect(
      h
        .find('hilos-table-card-room:99')
        ?.querySelector('[data-id="hilos-daemon-agent-log-room:99"]'),
    ).toBeNull()
    expect(h.find('hilos-daemon-agent-restart-room:99')).not.toBeNull()
  })
})

import { openNodePage } from './helpers/hilosDaemonNode.js'
import {
  DAEMON_CRON_NAME_FIELD,
  DAEMON_CRON_NEXT_RUN_FIELD,
  DAEMON_CRON_REPORTED_DATA,
  DAEMON_NODE_DATA,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  hilosAdminAccess,
  ingest,
  type HilosConnection,
  type TableRow,
  type TableSortOrder,
} from '@hilos/core'
import { describe, expect, it, vi } from 'vitest'

import { HilosDaemonCronPage } from '../src/admin/daemon/HilosDaemonCronPage.js'
import { daemonHarness } from './helpers/hilosDaemon.js'
import { bindTestTimeZone } from './timeZoneTestHarness.js'

function harness(projectTimeZone?: string) {
  return daemonHarness(
    HilosDaemonCronPage,
    HilosPages.DAEMON_CRON,
    undefined,
    projectTimeZone,
  )
}

/** Check each user action's transport request and retain its response id. */
function expectQuery(
  h: ReturnType<typeof harness>,
  filter: Record<string, string>,
  sort: TableSortOrder | null,
): void {
  expect(h.sendViewport).toHaveBeenCalledTimes(1)
  expect(h.sendViewport).toHaveBeenLastCalledWith(
    HilosPages.DAEMON_CRON,
    'hilosDaemonCron',
    expect.objectContaining({
      filter,
      sort,
      anchor: null,
      pageIndex: null,
      requestId: expect.stringMatching(/\S/),
    }),
  )
}

/** Answer the tracked request before starting the next action's call count. */
function answerQuery(
  h: ReturnType<typeof harness>,
  rows: readonly TableRow[],
): void {
  h.window('hilosDaemonCron', rows)
  h.sendViewport.mockClear()
}

/** Type through the real table input without reaching into its controller. */
function enterSearch(h: ReturnType<typeof harness>, value: string): void {
  const input = h.el('hilos-table-search') as HTMLInputElement
  expect(input.tagName).toBe('INPUT')
  expect(input.type).toBe('search')
  expect(input.getAttribute('aria-label')).toBe('Find by rule or agent')
  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
  h.detect()
}

function button(h: ReturnType<typeof harness>, id: string): HTMLButtonElement {
  const control = h.el(id) as HTMLButtonElement
  expect(control.tagName).toBe('BUTTON')
  expect(control.type).toBe('button')
  return control
}

function handshakeConnection(
  base: HilosConnection,
  initialProjectTimeZone?: string,
) {
  let projectTimeZone = initialProjectTimeZone
  const listeners = new Set<(value: never) => void>()
  const connection = {
    ...base,
    get projectTimeZone() {
      return projectTimeZone
    },
    on(event: string, listener: (value: never) => void) {
      if (event !== 'handshake') {
        return base.on(event as never, listener as never)
      }
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
  }
  return {
    connection: connection as unknown as HilosConnection,
    listenerCount: () => listeners.size,
    welcome(timeZone?: string): void {
      projectTimeZone = timeZone
      for (const listener of listeners) listener(undefined as never)
    },
  }
}
function rule(
  name: string,
  agentId: string | null = null,
  idleReason: string | null = null,
  lastRunAt: number | null = 1700000000000,
  nextRunAt: number | null = 1700001800000,
) {
  return {
    rowKey: `${agentId ?? ''}/${name}`,
    slots: {
      rule: {
        name,
        agentId,
        expression: '*/30 * * * *',
        lastRunAt,
        nextRunAt,
        idleReason,
      },
    },
  }
}

describe('HilosDaemonCronPage', () => {
  it('shows the initial placeholder, then waiting, silent and reported-empty explanations', () => {
    const h = harness()
    expect(h.el('hilos-table-loading')).not.toBeNull()
    h.window('hilosDaemonCron', [])
    expect(h.el('hilos-daemon-cron-empty-waiting')?.textContent).toContain(
      'not zero rules',
    )
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      name: null,
      role: null,
      state: 'silent',
      silentSince: 1700000000000,
      clock: null,
    })
    h.detect()
    expect(h.el('hilos-daemon-cron-empty-silent')).not.toBeNull()
    h.scope.data.set(DAEMON_CRON_REPORTED_DATA, true)
    h.detect()
    expect(h.el('hilos-daemon-cron-empty-none')).not.toBeNull()
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it('keeps picture order and the standby idle notice before a sort', () => {
    const h = harness()
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      name: null,
      role: null,
      state: 'standby',
      silentSince: null,
      clock: null,
    })
    h.window('hilosDaemonCron', [
      rule('z-last', null, 'not_leader'),
      rule('a-first', 'room:7'),
    ])
    expect(
      h.el('hilos-daemon-cron-not-leader')?.getAttribute('data-notice'),
    ).toBe('idle_until_leader')
    expect(h.el('hilos-daemon-cron-not-leader')?.textContent).toContain(
      'leadership moves',
    )
    expect(h.el('hilos-daemon-cron-executor-/z-last')?.textContent).toContain(
      'Daemon',
    )
    expect(
      h.el('hilos-daemon-cron-executor-room:7/a-first')?.textContent,
    ).toContain('room:7')
    expect(
      h.root.querySelector('tbody')?.textContent?.indexOf('z-last'),
    ).toBeLessThan(
      h.root.querySelector('tbody')!.textContent!.indexOf('a-first'),
    )
    expect(h.el('hilos-table-search')?.getAttribute('placeholder')).toBe(
      'Find by rule or agent',
    )
    expect(h.root.querySelectorAll('[aria-sort="none"]')).toHaveLength(2)
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      name: null,
      role: null,
      state: 'silent',
      silentSince: 1700000000000,
      clock: null,
    })
    h.detect()
    expect(h.el('hilos-daemon-cron-silent')?.textContent).toContain(
      'last report',
    )
    expect(
      h.el('hilos-daemon-cron-not-leader')?.getAttribute('data-notice'),
    ).toBe('idle')
    h.fixture.destroy()
    expect(h.listeners()).toBe(0)
  })

  it.each([
    [DAEMON_CRON_NAME_FIELD, 'Rule', DAEMON_CRON_NEXT_RUN_FIELD],
    [DAEMON_CRON_NEXT_RUN_FIELD, 'Next run', DAEMON_CRON_NAME_FIELD],
  ])(
    'sends the %s sort cycle with search and preserves node and sort on every search clear',
    (field, label, otherField) => {
      const h = harness()
      try {
        h.window('hilosDaemonCron', [rule('cleanup', 'room:7')])
        h.sendViewport.mockClear()
        enterSearch(h, 'cleanup')
        expectQuery(h, { node: 'n1', search: 'cleanup' }, null)
        answerQuery(h, [rule('cleanup', 'room:7')])

        const sortButton = button(h, `hilos-table-sort-${field}`)
        const otherButton = button(h, `hilos-table-sort-${otherField}`)
        expect(sortButton.textContent?.trim()).toBe(label)
        for (const [direction, ariaSort] of [
          ['asc', 'ascending'],
          ['desc', 'descending'],
          [null, 'none'],
        ] as const) {
          sortButton.click()
          h.detect()
          expectQuery(
            h,
            { node: 'n1', search: 'cleanup' },
            direction === null ? null : [{ field, direction }],
          )
          expect(sortButton.closest('th')?.getAttribute('aria-sort')).toBe(
            ariaSort,
          )
          expect(otherButton.closest('th')?.getAttribute('aria-sort')).toBe(
            'none',
          )
          answerQuery(h, [rule('cleanup', 'room:7')])
        }

        otherButton.click()
        h.detect()
        expectQuery(h, { node: 'n1', search: 'cleanup' }, [
          { field: otherField, direction: 'asc' },
        ])
        expect(otherButton.closest('th')?.getAttribute('aria-sort')).toBe(
          'ascending',
        )
        expect(sortButton.closest('th')?.getAttribute('aria-sort')).toBe('none')
        answerQuery(h, [rule('cleanup', 'room:7')])
        sortButton.click()
        h.detect()
        const sort: TableSortOrder = [{ field, direction: 'asc' }]
        expectQuery(h, { node: 'n1', search: 'cleanup' }, sort)
        expect(sortButton.closest('th')?.getAttribute('aria-sort')).toBe(
          'ascending',
        )
        expect(otherButton.closest('th')?.getAttribute('aria-sort')).toBe(
          'none',
        )
        answerQuery(h, [rule('cleanup', 'room:7')])

        for (const clear of ['input', 'button', 'whitespace'] as const) {
          enterSearch(h, 'room:7')
          expectQuery(h, { node: 'n1', search: 'room:7' }, sort)
          answerQuery(h, [rule('cleanup', 'room:7')])
          if (clear === 'button') {
            const clearButton = button(h, 'hilos-table-search-clear')
            expect(clearButton.getAttribute('aria-label')).toBe('Clear search')
            clearButton.click()
            h.detect()
          } else {
            enterSearch(h, clear === 'input' ? '' : '   ')
          }
          expectQuery(h, { node: 'n1' }, sort)
          expect(h.el('hilos-table-search-clear')).toBeNull()
          answerQuery(h, [rule('cleanup', 'room:7')])
          expect((h.el('hilos-table-search') as HTMLInputElement).value).toBe(
            '',
          )
        }
      } finally {
        h.fixture.destroy()
      }
      expect(h.listeners()).toBe(0)
    },
  )

  it('waits for an empty server window before showing Nothing found and resets to the route node', () => {
    vi.useFakeTimers()
    const h = harness()
    try {
      h.scope.data.set(DAEMON_CRON_REPORTED_DATA, true)
      h.window('hilosDaemonCron', [rule('cleanup', 'room:7')])
      h.sendViewport.mockClear()
      enterSearch(h, 'no-such-rule-or-agent')
      expectQuery(h, { node: 'n1', search: 'no-such-rule-or-agent' }, null)
      expect(h.el('hilos-table-row-room:7/cleanup')).not.toBeNull()
      expect(h.el('hilos-table-no-matches')).toBeNull()

      answerQuery(h, [])
      vi.advanceTimersByTime(400)
      h.detect()
      expect(h.el('hilos-table-loading')).toBeNull()
      const emptyStates = h.root.querySelectorAll(
        '[data-id="hilos-table-no-matches"]',
      )
      expect(emptyStates).toHaveLength(2)
      for (const empty of emptyStates) {
        expect(empty.textContent).toContain('Nothing found')
        expect(
          empty
            .querySelector('[data-id="hilos-table-no-matches-terms"]')
            ?.textContent?.trim(),
        ).toBe('No rows match “no-such-rule-or-agent”')
      }
      for (const state of ['waiting', 'silent', 'none']) {
        expect(h.el(`hilos-daemon-cron-empty-${state}`)).toBeNull()
      }
      const reset = button(h, 'hilos-table-no-matches-reset')
      expect(reset.textContent?.trim()).toBe('Reset filters')
      reset.click()
      h.detect()
      expectQuery(h, { node: 'n1' }, null)
      expect((h.el('hilos-table-search') as HTMLInputElement).value).toBe('')
      answerQuery(h, [rule('restored')])
      vi.advanceTimersByTime(400)
      h.detect()
      expect(h.el('hilos-table-loading')).toBeNull()
      expect(h.el('hilos-table-no-matches')).toBeNull()
      expect(h.el('hilos-table-row-/restored')).not.toBeNull()
      expect(h.el('hilos-table-row-room:7/cleanup')).toBeNull()
    } finally {
      h.fixture.destroy()
      vi.useRealTimers()
    }
    expect(h.listeners()).toBe(0)
  })

  it.each([true, false])(
    'preserves sort when resetting filters and restores reported-empty for admin=%s',
    (admin) => {
      vi.useFakeTimers()
      const h = harness()
      ingest(h.scopes.session, {
        entities: { currentUser: { id: 3, admin } },
        data: { adminViewMode: true },
      })
      const releaseAccess = bindAdminAccess(h.scopes)
      try {
        h.detect()
        expect(hilosAdminAccess.get()).toBe(admin ? 'full' : 'view')
        h.scope.data.set(DAEMON_CRON_REPORTED_DATA, true)
        h.window('hilosDaemonCron', [rule('cleanup', 'room:7')])
        h.sendViewport.mockClear()
        const sortButton = button(h, 'hilos-table-sort-name')
        sortButton.click()
        h.detect()
        const sort: TableSortOrder = [{ field: 'name', direction: 'asc' }]
        expectQuery(h, { node: 'n1' }, sort)
        answerQuery(h, [rule('cleanup', 'room:7')])

        enterSearch(h, 'no-such-rule-or-agent')
        expectQuery(h, { node: 'n1', search: 'no-such-rule-or-agent' }, sort)
        answerQuery(h, [])
        vi.advanceTimersByTime(400)
        h.detect()
        expect(h.el('hilos-table-loading')).toBeNull()
        expect(h.el('hilos-table-no-matches')?.textContent).toContain(
          'Nothing found',
        )
        expect(h.el('hilos-daemon-cron-empty-none')).toBeNull()
        expect(sortButton.closest('th')?.getAttribute('aria-sort')).toBe(
          'ascending',
        )

        button(h, 'hilos-table-no-matches-reset').click()
        h.detect()
        expectQuery(h, { node: 'n1' }, sort)
        expect((h.el('hilos-table-search') as HTMLInputElement).value).toBe('')
        expect(sortButton.closest('th')?.getAttribute('aria-sort')).toBe(
          'ascending',
        )
        answerQuery(h, [])
        vi.advanceTimersByTime(400)
        h.detect()
        expect(h.el('hilos-table-loading')).toBeNull()
        expect(h.el('hilos-daemon-cron-empty-none')?.textContent?.trim()).toBe(
          'The node reports no cron rules',
        )
        expect(h.el('hilos-table-no-matches')).toBeNull()
      } finally {
        h.fixture.destroy()
        releaseAccess()
        vi.useRealTimers()
      }
      expect(h.listeners()).toBe(0)
    },
  )

  it('rebinds on node and context changes and releases the old heading watchers', () => {
    const h = harness()
    h.route.set({
      page: HilosPages.DAEMON_CRON,
      params: { nodeId: 'n2' },
      admin: true,
    })
    h.detect()
    expect(h.unregister).toHaveBeenCalledTimes(1)
    h.window('hilosDaemonCron', [])
    expect(h.register.mock.calls.at(-1)?.[1].descriptor().filter).toEqual({
      node: 'n2',
    })
    h.sendViewport.mockClear()
    enterSearch(h, 'cleanup')
    expectQuery(h, { node: 'n2', search: 'cleanup' }, null)
    const scopes = new ScopeManager()
    const replacement = openNodePage(scopes, HilosPages.DAEMON_CRON)
    h.fixture.componentRef.setInput('context', { ...h.context, scopes })
    h.detect()
    expect(h.unregister).toHaveBeenCalledTimes(2)
    h.window('hilosDaemonCron', [rule('replacement')])
    h.sendViewport.mockClear()
    enterSearch(h, 'replacement')
    expectQuery(h, { node: 'n2', search: 'replacement' }, null)
    replacement.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      name: null,
      role: null,
      state: 'leader',
      silentSince: null,
      clock: null,
    })
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      name: null,
      role: null,
      state: 'silent',
      silentSince: 1700000000000,
      clock: null,
    })
    h.detect()
    expect(h.el('hilos-daemon-node-state')?.textContent).toContain('Leader')
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalledTimes(3)
    expect(h.listeners()).toBe(0)
  })

  it('shows the connection project zone from handshakes and releases its old listener', () => {
    const h = harness('Europe/Warsaw')
    const zone = () => h.el('hilos-daemon-cron-project-time-zone')?.textContent
    let destroyed = false
    const destroy = (): void => {
      if (!destroyed) {
        h.fixture.destroy()
        destroyed = true
      }
    }
    let replacement: ReturnType<typeof handshakeConnection> | undefined
    try {
      h.detect()
      expect(zone()).toBe('Europe/Warsaw')
      h.window('hilosDaemonCron', [rule('project-zone')])
      const schedule = () => {
        const cells = [...h.root.querySelectorAll('tbody tr td')]
        return {
          expression: cells[2]?.textContent,
          runTimes: cells.slice(-2).map((cell) => cell.textContent),
        }
      }
      const initialSchedule = schedule()
      expect(
        h
          .el('hilos-daemon-cron-time-zone')
          ?.textContent?.replace(/\s+/g, ' ')
          .trim(),
      ).toBe(
        "Expressions are evaluated in the project's time zone — Europe/Warsaw. Run times are shown in your time zone.",
      )
      expect(h.listeners('handshake')).toBe(1)
      h.setProjectTimeZone('America/New_York')
      h.emit('handshake', undefined)
      h.detect()
      expect(zone()).toBe('America/New_York')
      expect(schedule()).toEqual(initialSchedule)
      expect(h.register).toHaveBeenCalledTimes(1)
      expect(h.unregister).not.toHaveBeenCalled()
      h.setProjectTimeZone(undefined)
      h.emit('handshake', undefined)
      h.detect()
      expect(zone()).toBe('—')

      const scopes = new ScopeManager()
      openNodePage(scopes, HilosPages.DAEMON_CRON)
      replacement = handshakeConnection(h.connection, 'Europe/London')
      h.fixture.componentRef.setInput('context', {
        ...h.context,
        connection: replacement.connection,
        scopes,
      })
      h.detect()
      expect(h.listeners('handshake')).toBe(0)
      expect(replacement.listenerCount()).toBe(1)
      expect(zone()).toBe('Europe/London')

      replacement.welcome()
      h.detect()
      expect(zone()).toBe('—')
      destroy()
      expect(replacement.listenerCount()).toBe(0)
      expect(h.listeners()).toBe(0)
    } finally {
      destroy()
    }
  })

  it('reformats run and silence moments in the reader zone without replacing the table', () => {
    const timeZone = bindTestTimeZone('UTC')
    const h = harness()
    try {
      timeZone.receive('UTC')
      h.scope.data.set(DAEMON_NODE_DATA, {
        clustered: true,
        name: null,
        role: null,
        state: 'silent',
        silentSince: 1700000000000,
        clock: null,
      })
      h.window('hilosDaemonCron', [
        rule('reader-zone'),
        rule('idle', null, 'not_leader'),
        rule('never-ran', null, null, null, null),
      ])
      h.detect()

      const table = h.root.querySelector('hilos-viewport-table')
      const readerRow = () =>
        [...h.root.querySelectorAll('tbody tr')].find((row) =>
          row.textContent?.includes('reader-zone'),
        )!
      const cells = () =>
        [...readerRow().querySelectorAll('td')].slice(-2) as HTMLElement[]
      const initialRunCells = cells()
      const initialRuns = initialRunCells.map((cell) => cell.textContent)
      const silentLine = h.el('hilos-daemon-cron-silent')!
      const initialSilent = silentLine.textContent
      expect(initialRunCells).toHaveLength(2)
      expect(h.root.querySelector('tbody')?.textContent).toContain(
        'Not on this node',
      )
      expect(h.root.querySelector('tbody')?.textContent).toContain('Not yet')
      expect(h.root.querySelector('tbody')?.textContent).toContain('Never')
      expect(h.register).toHaveBeenCalledTimes(1)

      timeZone.receive('Pacific/Honolulu')
      h.detect()

      const updatedRunCells = cells()
      expect(updatedRunCells[0]).toBe(initialRunCells[0])
      expect(updatedRunCells[1]).toBe(initialRunCells[1])
      expect(updatedRunCells.map((cell) => cell.textContent)).not.toEqual(
        initialRuns,
      )
      expect(h.root.querySelector('tbody')?.textContent).toContain(
        'Not on this node',
      )
      expect(h.root.querySelector('tbody')?.textContent).toContain('Not yet')
      expect(h.root.querySelector('tbody')?.textContent).toContain('Never')
      expect(h.el('hilos-daemon-cron-silent')).toBe(silentLine)
      expect(silentLine.textContent).not.toBe(initialSilent)
      expect(h.root.querySelector('hilos-viewport-table')).toBe(table)
      expect(h.register).toHaveBeenCalledTimes(1)
      expect(h.unregister).not.toHaveBeenCalled()
    } finally {
      h.fixture.destroy()
      vi.restoreAllMocks()
      timeZone.receive(null)
      timeZone.release()
    }
  })
})

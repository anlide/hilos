import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, screen, within } from '@testing-library/react'
import {
  CHANGE_LOG_TABLE_COPY,
  CHANGE_LOG_TABLE_SECTION,
  HilosPages,
  bindAdminAccess,
  bindSessionScope,
  hilosAdminAccess,
} from '@hilos/core'

import {
  BOT,
  TABLE_TABS,
  answerTable,
  mountTable as mountOverview,
  tableHarness,
  tableRouter,
  updateTable,
} from './helpers/changeLogTable.js'

const SESSION = {
  name: 'hilos_session',
  owner: 'framework',
  journaled: false,
  changesLast24h: null,
  lastChangeAt: null,
  columns: [
    {
      name: 'id',
      sqlType: 'int',
      mode: null,
      storage: null,
      noiseReason: null,
    },
  ],
  triggers: [],
}

const releases: (() => void)[] = []

function element(id: string, root: ParentNode = document): HTMLElement {
  const found = root.querySelector(`[data-id="${id}"]`)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

function text(node: Element): string {
  return (node.textContent ?? '').replace(/\s+/g, ' ').trim()
}

describe('HilosChangeLogTablePage', () => {
  afterEach(() => {
    cleanup()
    for (const release of releases.splice(0)) release()
  })

  it('draws Overview with its entity head and three linked summaries without bindings', () => {
    const h = tableHarness()
    const { container } = mountOverview(h)
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.getByRole('heading', { level: 1, name: 'bot' })).toBeTruthy()
    expect(
      Array.from(
        element('hilos-change-log-table-head-badges', container).children,
        text,
      ),
    ).toEqual(['Project', CHANGE_LOG_TABLE_COPY.journaled])
    expect(
      text(element('hilos-change-log-table-head-description', container)),
    ).toBe('Journaled since migration 071')
    const tiles = ['history', 'columns', 'triggers'].map((part) =>
      element(`hilos-change-log-table-link-${part}`, container),
    )
    expect(
      tiles.map((tile) => [tile.tagName, tile.getAttribute('href')]),
    ).toEqual([
      ['A', '/hilos/change-log/table/bot/history'],
      ['A', '/hilos/change-log/table/bot/columns'],
      ['A', '/hilos/change-log/table/bot/triggers'],
    ])
    expect(
      tiles.map(
        (tile) => within(tile).getByRole('heading', { level: 2 }).textContent,
      ),
    ).toEqual(['Changes in 24 hours', 'Columns', 'Triggers'])
    for (const [index, count, summary] of [
      [0, '3', 'Last change: —'],
      [1, '5', 'with value 2 · as fact 1 · noisy 1'],
      [2, '3', 'Service 3'],
    ] as const) {
      expect(within(tiles[index]).getByText(count)).toBeTruthy()
      expect(within(tiles[index]).getByText(summary)).toBeTruthy()
    }
    for (const icon of container.querySelectorAll('i'))
      expect(icon.closest('[aria-hidden="true"]')).not.toBeNull()
    for (const id of [
      'hilos-change-log-table-header',
      'hilos-viewport-table',
      'hilos-change-log-table-columns',
      'hilos-change-log-table-triggers',
      'modal',
    ])
      expect(document.querySelector(`[data-id="${id}"]`)).toBeNull()
    expect(h.source.sendAction).not.toHaveBeenCalled()
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
    expect(h.connection.on).not.toHaveBeenCalled()
  })

  it('keeps the total column count, combines facts and omits zero modes', () => {
    const h = tableHarness({
      ...BOT,
      changesLast24h: 0,
      columns: [
        BOT.columns[0],
        BOT.columns[3],
        { ...BOT.columns[3], name: 'photo', mode: 'binary' },
        { ...BOT.columns[3], name: 'token', mode: 'secret' },
      ],
    })
    const { container } = mountOverview(h)
    const columns = element('hilos-change-log-table-link-columns', container)
    expect(within(columns).getByText('4')).toBeTruthy()
    expect(within(columns).getByText('as fact 2 · secret 1')).toBeTruthy()
    expect(
      within(
        element('hilos-change-log-table-link-history', container),
      ).getByText('0'),
    ).toBeTruthy()
    updateTable(h, { ...BOT, changesLast24h: null, columns: [] })
    expect(within(columns).getByText('0')).toBeTruthy()
    expect(
      within(
        element('hilos-change-log-table-link-history', container),
      ).getByText('—'),
    ).toBeTruthy()
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    expect(h.source.sendAction).not.toHaveBeenCalled()
  })

  it('uses only eligible server tabs for tiles even when other routes are built', () => {
    const h = tableHarness()
    const { container } = mountOverview(h)
    act(() => h.scope.data.set('pageTabs', [TABLE_TABS[0], TABLE_TABS[2]]))
    expect(
      Array.from(
        container.querySelectorAll('[data-id^="hilos-change-log-table-link-"]'),
        (tile) => tile.getAttribute('data-id'),
      ),
    ).toEqual(['hilos-change-log-table-link-triggers'])
    const tabs = screen.getByRole('navigation', { name: 'Table pages' })
    expect(
      within(tabs)
        .getAllByRole('link')
        .map((link) => link.textContent),
    ).toEqual(['Overview', 'Triggers'])
  })

  it('omits links to unbuilt pages and hides the row when only Overview remains', () => {
    const h = tableHarness()
    const r = tableRouter(h, { tableId: 'bot' }, [
      HilosPages.CHANGE_LOG_TABLE_COLUMNS,
      HilosPages.CHANGE_LOG_TABLE_TRIGGERS,
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
    ])
    const { container } = mountOverview(h, r)
    expect(screen.getByRole('heading', { name: 'bot', level: 1 })).toBeTruthy()
    expect(screen.queryByRole('navigation', { name: 'Table pages' })).toBeNull()
    expect(
      container.querySelectorAll('[data-id^="hilos-change-log-table-link-"]'),
    ).toHaveLength(0)
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })

  it('draws an untracked table with its explanation, two tabs and only the triggers tile', () => {
    const h = tableHarness(SESSION)
    const { container } = mountOverview(
      h,
      tableRouter(h, { tableId: 'hilos_session' }),
    )
    expect(
      screen.getByRole('heading', { name: 'hilos_session', level: 1 }),
    ).toBeTruthy()
    expect(text(element('hilos-change-log-table-head-badges', container))).toBe(
      'Framework',
    )
    expect(
      text(element('hilos-change-log-table-head-description', container)),
    ).toBe(CHANGE_LOG_TABLE_COPY.untracked)
    expect(screen.getAllByText(CHANGE_LOG_TABLE_COPY.untracked)).toHaveLength(2)
    expect(
      Array.from(
        container.querySelectorAll('[data-id^="hilos-change-log-table-link-"]'),
        (link) => link.getAttribute('href'),
      ),
    ).toEqual(['/hilos/change-log/table/hilos_session/triggers'])
    expect(
      within(
        element('hilos-change-log-table-link-triggers', container),
      ).getByText('0'),
    ).toBeTruthy()
    expect(
      within(
        element('hilos-change-log-table-link-triggers', container),
      ).getByText('Service 0'),
    ).toBeTruthy()
    const tabs = screen.getByRole('navigation', { name: 'Table pages' })
    expect(
      within(tabs)
        .getAllByRole('link')
        .map((link) => link.textContent),
    ).toEqual(['Overview', 'Triggers'])
    for (const zone of [
      'hilos-change-log-table-columns',
      'hilos-change-log-table-triggers',
      'hilos-viewport-table',
    ]) {
      expect(container.querySelector(`[data-id="${zone}"]`)).toBeNull()
    }
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    expect(h.connection.on).not.toHaveBeenCalled()
  })

  it('draws no entity content when the section is damaged', () => {
    const h = tableHarness({ ...BOT, owner: 'nobody' })
    const { container } = mountOverview(h)
    for (const id of [
      'hilos-change-log-table-header',
      'hilos-change-log-table-head-badges',
      'hilos-viewport-table',
    ])
      expect(container.querySelector(`[data-id="${id}"]`)).toBeNull()
    expect(
      container.querySelectorAll('[data-id^="hilos-change-log-table-link-"]'),
    ).toHaveLength(0)
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    expect(h.connection.on).not.toHaveBeenCalled()
  })

  it.each(['full', 'view'] as const)(
    'keeps %s metadata visible on Overview without history bindings',
    (access) => {
      const h = tableHarness()
      bindSessionScope(h.context.connection, h.context.scopes)
      releases.push(bindAdminAccess(h.context.scopes))
      h.emit('projectSignal', {
        type: 'handshake_response',
        data: {
          entities: {
            currentUser: { id: 9, name: 'Ada', admin: access === 'full' },
          },
          data: { adminViewMode: true },
        },
      })
      expect(hilosAdminAccess.get()).toBe(access)
      const section = h.context.scopes
        .pageDataSignal(CHANGE_LOG_TABLE_SECTION)
        .get()
      answerTable(
        h.context.scopes.openPage(HilosPages.CHANGE_LOG_TABLE),
        section,
      )
      const { container } = mountOverview(h)
      expect(
        screen.getByRole('heading', { name: 'bot', level: 1 }),
      ).toBeTruthy()
      expect(container.textContent).not.toContain('Ada')
      expect(
        document.querySelector('[data-id="hilos-viewport-table"]'),
      ).toBeNull()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
      expect(h.source.sendAction).not.toHaveBeenCalled()
    },
  )
})

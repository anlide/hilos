import { act, cleanup, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosPages, resolvePageTitle } from '@hilos/core/base'
import {
  BOT,
  TABLE_TABS,
  answerTable,
  mountTable,
  tableHarness,
  tableRouter,
  updateTable,
} from './helpers/changeLogTable.js'

afterEach(cleanup)

/** Read breadcrumb captions without their decorative separators. */
function crumbLabels(): string[] {
  return Array.from(
    screen
      .getByRole('navigation', { name: 'Breadcrumb' })
      .querySelectorAll('li'),
    (item) => {
      const copy = item.cloneNode(true) as HTMLElement
      copy
        .querySelectorAll('[aria-hidden="true"]')
        .forEach((node) => node.remove())
      return copy.textContent!.trim()
    },
  )
}

/** The title follows the same identity that bootHilos reads. */
function title(r: ReturnType<typeof tableRouter>): string {
  const identity = r.router.pageIdentity.get()
  return resolvePageTitle(
    r.route.get().page,
    {},
    'Test app',
    identity?.label,
    true,
    identity?.pageHeadOwner?.label,
  )
}

describe('HilosChangeLogTableShell', () => {
  it.each(TABLE_TABS)(
    'uses the current $label answer for the head, breadcrumb and title',
    ({ page }) => {
      const h = tableHarness(BOT, page)
      const r = tableRouter(h)
      const { container } = mountTable(h, r, true)
      expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
      const heading = screen.getByRole('heading', { level: 1, name: 'bot' })
      expect(heading.id).not.toBe('')
      expect(crumbLabels()).toEqual([
        'Hilos',
        'Change Log',
        'Tracked tables',
        'bot',
      ])
      const crumbs = screen.getByRole('navigation', { name: 'Breadcrumb' })
      expect(
        within(crumbs)
          .getAllByRole('link')
          .map((link) => link.getAttribute('href')),
      ).toEqual(['/hilos', '/hilos/change-log', '/hilos/change-log/tables'])
      const last = crumbs.querySelector('[aria-current="page"]')!
      expect(last.tagName).toBe('LI')
      expect(last.querySelector('a')).toBeNull()
      expect(title(r)).toBe('bot · Test app')
      const tabs = screen.getByRole('navigation', { name: 'Table pages' })
      expect(
        within(tabs)
          .getAllByRole('link')
          .map((link) => [link.textContent, link.getAttribute('href')]),
      ).toEqual([
        ['Overview', '/hilos/change-log/tables/bot'],
        ['Columns', '/hilos/change-log/tables/bot/columns'],
        ['Triggers', '/hilos/change-log/tables/bot/triggers'],
        ['History', '/hilos/change-log/tables/bot/history'],
      ])
      expect(tabs.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
      expect(
        tabs.querySelector('[aria-current="page"]')!.getAttribute('data-id'),
      ).toBe(`hilos-page-tab-${page}`)
      expect(screen.queryByRole('tablist')).toBeNull()
      for (const id of [
        'hilos-admin-children',
        'hilos-admin-title',
        'hilos-change-log-table-header',
      ])
        expect(container.querySelector(`[data-id="${id}"]`)).toBeNull()
      const visual = container.querySelector(
        '[data-id="hilos-change-log-table-head-icon"]',
      )!
      expect(visual.getAttribute('aria-hidden')).toBe('true')
      for (const icon of container.querySelectorAll('i'))
        expect(icon.closest('[aria-hidden="true"]')).not.toBeNull()
      expect(screen.getByText('Table body')).toBeTruthy()
      expect(h.source.sendAction).not.toHaveBeenCalled()
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
      expect(h.connection.on).not.toHaveBeenCalled()
    },
  )

  it.each([
    ['57', '/hilos/change-log/tables/bot/history/57'],
    [
      'part,with%2Ccomma',
      '/hilos/change-log/tables/bot/history/part%2Cwith%252Ccomma',
    ],
  ])('keeps record %s only in the History address', (record, href) => {
    const h = tableHarness(BOT, HilosPages.CHANGE_LOG_TABLE_HISTORY)
    mountTable(h, tableRouter(h, { tableId: 'bot', record }), true)
    const tabs = screen.getByRole('navigation', { name: 'Table pages' })
    expect(
      within(tabs)
        .getAllByRole('link')
        .map((link) => link.getAttribute('href')),
    ).toEqual([
      '/hilos/change-log/tables/bot',
      '/hilos/change-log/tables/bot/columns',
      '/hilos/change-log/tables/bot/triggers',
      href,
    ])
  })

  it('shows only an aria-hidden skeleton before its answer and releases the live name on unmount', () => {
    const h = tableHarness(BOT, HilosPages.CHANGE_LOG_TABLE, false)
    const r = tableRouter(h)
    const { container, unmount } = mountTable(h, r, true)
    expect(
      container
        .querySelector('[data-id="hilos-page-head-skeleton"]')!
        .getAttribute('aria-hidden'),
    ).toBe('true')
    expect(screen.queryByRole('heading')).toBeNull()
    expect(screen.queryByRole('navigation')).toBeNull()
    expect(screen.queryByText('Table body')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-change-log-table-head-icon"]'),
    ).toBeNull()
    act(() => answerTable(h.scope))
    expect(screen.getByRole('heading', { level: 1, name: 'bot' })).toBeTruthy()
    expect(title(r)).toBe('bot · Test app')
    unmount()
    expect(r.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Tracked table',
    )
    expect(title(r)).toBe('Tracked table · Test app')
  })

  it('drops the old name and body on a new address, scope, page or refusal', () => {
    const h = tableHarness()
    const r = tableRouter(h)
    const { container } = mountTable(h, r, true)
    const expectReset = () => {
      expect(container.textContent).not.toContain('bot')
      expect(title(r)).not.toContain('bot')
      expect(
        container.querySelector(
          '[data-id="hilos-change-log-table-head-badges"]',
        ),
      ).toBeNull()
      expect(screen.queryByText('Table body')).toBeNull()
    }
    act(() =>
      r.route.set({
        page: HilosPages.CHANGE_LOG_TABLE,
        params: { tableId: 'other' },
        admin: true,
      }),
    )
    expectReset()
    act(() => h.context.scopes.openPage(HilosPages.CHANGE_LOG_TABLE))
    expectReset()
    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).not.toBeNull()
    expect(screen.queryByRole('heading')).toBeNull()
    expect(screen.queryByRole('navigation')).toBeNull()
    updateTable(h, { ...BOT, name: 'other' })
    expect(
      screen.getByRole('heading', { name: 'other', level: 1 }),
    ).toBeTruthy()
    expect(title(r)).toBe('other · Test app')
    act(() =>
      r.route.set({
        page: HilosPages.CHANGE_LOG_TABLE_COLUMNS,
        params: { tableId: 'other' },
        admin: true,
      }),
    )
    expect(title(r)).toBe('Tracked table · Test app')
    expect(screen.queryByText('Table body')).toBeNull()
    act(() =>
      r.route.set({
        page: HilosPages.CHANGE_LOG_TABLE,
        params: { tableId: 'other' },
        admin: true,
      }),
    )
    expect(title(r)).toBe('other · Test app')
    act(() =>
      r.error.set({
        httpCode: 404,
        errorCode: 'not_found',
        title: 'Not found',
        message: 'Table not found.',
      }),
    )
    expect(title(r)).toBe('Tracked table · Test app')
    expect(screen.queryByText('Table body')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-change-log-table-head-badges"]'),
    ).toBeNull()
    act(() => r.error.set(null))
    expect(title(r)).toBe('other · Test app')
    act(() => {
      const scope = h.context.scopes.openPage(HilosPages.CHANGE_LOG)
      answerTable(scope, { ...BOT, name: 'other' })
      r.route.set({
        page: HilosPages.CHANGE_LOG,
        params: { tableId: 'other' },
        admin: true,
      })
    })
    expect(title(r)).toBe('Tracked table · Test app')
    expect(screen.queryByText('Table body')).toBeNull()
    act(() => h.context.scopes.dropPage())
    expect(r.router.pageIdentity.get()).toBeUndefined()
    expect(screen.queryByRole('heading')).toBeNull()
  })

  it('uses the earliest migration, preserves literal long names, and invents no migration', () => {
    const name = '<script>table</script>' + 'long_table_name_'.repeat(20)
    const h = tableHarness({
      ...BOT,
      name,
      triggers: [
        { ...BOT.triggers[0], validFromMigration: 92 },
        { ...BOT.triggers[1], validFromMigration: 7 },
      ],
    })
    const r = tableRouter(h, { tableId: name })
    const { container } = mountTable(h, r, true)
    expect(screen.getByRole('heading', { name, level: 1 }).textContent).toBe(
      name,
    )
    expect(crumbLabels().at(-1)).toBe(name)
    expect(title(r)).toBe(`${name} · Test app`)
    expect(container.querySelector('script')).toBeNull()
    expect(
      container.querySelector(
        '[data-id="hilos-change-log-table-head-description"]',
      )!.textContent,
    ).toBe('Journaled since migration 007')
    updateTable(h, { ...BOT, name, triggers: [] })
    expect(
      container.querySelector(
        '[data-id="hilos-change-log-table-head-description"]',
      )!.textContent,
    ).toBe('')
    expect(screen.getByRole('heading', { name, level: 1 })).toBeTruthy()
  })
})

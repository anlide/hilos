// The Status Scheduled maintenance tab in React: the real page, the real core
// catalog state, table and schedule, change and cancel controllers, the real
// HilosViewportTable with its search, component filter, orders and Apply strip,
// and the real windows; only the wire is fake. Scenarios mirror
// framework/frontend/vue/src/admin/status/HilosStatusWorksPage.test.ts, plus the
// two the window tests leave to the page: one focus for one window, and the
// mail mark that waits for a real row echo. The shared section head and the
// windows' own flows are proven by their own tests.
import { StrictMode } from 'react'
import { act, fireEvent, render } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  formatMoment,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  hilosToasts,
} from '@hilos/core/base'

import { HilosStatusWorksPage } from '../src/admin/status/HilosStatusWorksPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { readerTimeZone } from './readerTimeZone.js'
import {
  announcement,
  statusWorksHarness,
  workRow,
  worksData,
} from './statusFixtures.js'

const cleanups: (() => void)[] = []
beforeEach(() => {
  vi.useFakeTimers()
})
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) act(cleanup)
  hilosToasts.clear()
  vi.useRealTimers()
})

/** The format both moments and the mail mark of a row are drawn in. */
const MOMENT_FORMAT: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  timeZoneName: 'short',
}

function find(
  id: string,
  root: ParentNode = document.body,
): HTMLElement | null {
  return root.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

function get(id: string, root: ParentNode = document.body): HTMLElement {
  const element = find(id, root)
  if (element === null) throw new Error(`Missing [data-id="${id}"]`)
  return element
}

function table(): HTMLTableElement {
  const element = document.querySelector('table')
  if (element === null) throw new Error('Missing table')
  return element
}

function modals(): HTMLElement[] {
  return [...document.body.querySelectorAll<HTMLElement>('[data-id="modal"]')]
}

function toasts(): string[] {
  return hilosToasts.toasts.get().map((toast) => toast.message)
}

async function settled() {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(0)
  })
}

/**
 * Mount the real page under the harness router on the Works page.
 *
 * @param viewer Whether the signed-in person views the admin view mode.
 * @param strict Whether to mount under StrictMode.
 */
function page(viewer = false, strict = false) {
  const h = statusWorksHarness(viewer)
  cleanups.push(h.dispose)
  const tree = (
    <HilosRouterContext.Provider value={h.router}>
      <HilosStatusWorksPage context={h.context} />
    </HilosRouterContext.Provider>
  )
  const view = render(strict ? <StrictMode>{tree}</StrictMode> : tree)
  let mounted = true
  const unmount = () => {
    if (!mounted) return
    mounted = false
    view.unmount()
  }
  cleanups.push(unmount)
  return {
    ...h,
    view,
    unmount,
    /**
     * Press the opener of one window the way the person does.
     *
     * @param kind The window to open: the bar's Schedule, or a row's change or cancel.
     * @param id The row whose opener to press.
     */
    async open(kind: 'create' | 'edit' | 'cancel', id = 7) {
      const button =
        kind === 'create'
          ? get('hilos-table-main-action')
          : get(`hilos-status-work-${kind}-${id}`, table())
      button.focus()
      fireEvent.click(button)
      await settled()
      return button
    },
  }
}

describe('HilosStatusWorksPage with real normalized rows and viewport', () => {
  it('renders the complete catalog without mount requests, its named columns and live reader-zone seconds', () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    act(() => zone.choose('Asia/Tokyo'))
    const h = page()
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
    expect(h.registered.size).toBe(1)
    h.window([
      workRow({
        title: '<b>Upgrade</b>',
        componentKeys: ['queue'],
        description: '<script>text</script>',
      }),
    ])
    expect(get('hilos-status-works-table')).not.toBeNull()
    expect(table().getAttribute('aria-labelledby')).toBe(
      h.view.container.querySelector('h1')!.id,
    )
    expect(
      [...table().querySelectorAll('thead th')].map((cell) =>
        cell.textContent?.trim(),
      ),
    ).toEqual(
      expect.arrayContaining([
        'Starts',
        'Ends',
        'Title',
        'Components',
        'Last mail queued',
      ]),
    )
    expect(
      table().querySelector('th[aria-sort="ascending"]')?.textContent,
    ).toContain('Starts')
    expect(table().textContent).toContain('Message queue')
    expect(get('hilos-status-work-title-7', table()).textContent).toBe(
      '<b>Upgrade</b>',
    )
    expect(table().textContent).toContain('<script>text</script>')
    expect(table().querySelector('script')).toBeNull()
    expect(get('hilos-status-work-mail-7', table()).textContent).toBe('—')
    expect(table().textContent).toContain(
      formatMoment(announcement.startsAt, { format: MOMENT_FORMAT })!,
    )
    expect(
      get('hilos-status-work-edit-7', table()).getAttribute('aria-label'),
    ).toBe('Change scheduled maintenance · <b>Upgrade</b>')
    expect(
      get('hilos-status-work-cancel-7', table()).getAttribute('aria-label'),
    ).toBe('Cancel scheduled maintenance · <b>Upgrade</b>')
    const before = table().textContent
    act(() => zone.choose('America/New_York'))
    expect(table().textContent).toContain(
      formatMoment(announcement.startsAt, { format: MOMENT_FORMAT })!,
    )
    expect(table().textContent).not.toBe(before)
    expect(h.sent).toEqual([])
    expect(find('hilos-status-works-unavailable')).toBeNull()
  })

  it('uses server search, component facets and six indexed orders without locally filtering the held rows', async () => {
    const h = page()
    h.window()
    const search = get('hilos-table-search')
    expect(search.getAttribute('aria-label')).toBe(
      'Search by title or description…',
    )
    fireEvent.change(search, { target: { value: 'no-match' } })
    expect(h.viewports.at(-1)).toEqual([
      'hilos_status_works',
      'hilosStatusWorks',
      expect.objectContaining({ filter: { search: 'no-match' } }),
    ])
    fireEvent.click(
      get('hilos-dropdown-toggle', get('hilos-table-filter-componentKey')),
    )
    fireEvent.click(get('hilos-dropdown-option-1'))
    expect(h.viewports.at(-1)).toEqual([
      'hilos_status_works',
      'hilosStatusWorks',
      expect.objectContaining({
        filter: { search: 'no-match', componentKey: 'queue' },
      }),
    ])
    expect(h.facets).toContainEqual([
      'hilos_status_works',
      'hilosStatusWorks',
      { componentKey: ['service', 'queue', 'database'] },
    ])
    expect(table().textContent).toContain('Database upgrade')
    for (const field of ['startsAt', 'endsAt', 'title']) {
      for (const direction of field === 'startsAt'
        ? ['desc', 'asc']
        : ['asc', 'desc']) {
        fireEvent.click(get(`hilos-table-sort-${field}`, table()))
        expect(h.viewports.at(-1)).toEqual([
          'hilos_status_works',
          'hilosStatusWorks',
          expect.objectContaining({ sort: [{ field, direction }] }),
        ])
      }
    }
    h.window([], worksData, { search: 'no-match', componentKey: 'queue' })
    expect(document.body.textContent).toContain('Nothing found')
    fireEvent.click(get('hilos-table-filter-reset'))
    h.window([])
    expect(document.body.textContent).toContain('No maintenance is scheduled.')
    fireEvent.click(get('hilos-table-empty-action'))
    await settled()
    expect(modals().map((modal) => modal.getAttribute('aria-label'))).toEqual([
      'Schedule maintenance',
    ])
    expect(h.sent).toEqual([])
  })

  it('applies row values live while holding membership for Apply, and never invents a mail queue mark', () => {
    const h = page()
    h.window([workRow(), workRow({ id: 8 })])
    h.delta({ kind: 'row_removed', rowKey: '8', reason: 'deleted' })
    h.update({
      title: 'Changed elsewhere',
      lastMailQueuedAt: 1_800_000_000_000,
    })
    h.data({ ...worksData!, upcomingCount: 0, nextWork: null })
    expect(get('hilos-status-work-title-7', table()).textContent).toBe(
      'Changed elsewhere',
    )
    expect(get('hilos-status-work-mail-7', table()).textContent).toBe(
      formatMoment(1_800_000_000_000, { format: MOMENT_FORMAT }),
    )
    expect(find('hilos-status-work-title-8', table())).not.toBeNull()
    expect(get('hilos-table-pending').textContent).toBe('1')
    fireEvent.click(get('hilos-table-apply'))
    expect(find('hilos-status-work-title-8', table())).toBeNull()
    expect(h.viewports).toEqual([])
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted', own: true })
    expect(find('hilos-status-work-title-7', table())).toBeNull()
  })

  it('keeps unavailable distinct from known empty and serves readable forms to a viewer with mutations marked closed', async () => {
    const h = page(true)
    h.window([], null)
    expect(get('hilos-status-works-unavailable').textContent).toBe(
      'Scheduled maintenance data is unavailable.',
    )
    expect(find('hilos-status-works-table')).toBeNull()
    h.window()
    expect(find('hilos-status-works-unavailable')).toBeNull()
    await h.open('edit')
    const save = get('hilos-status-work-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)
    expect(save.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    expect((get('hilos-status-work-title') as HTMLInputElement).disabled).toBe(
      false,
    )
    fireEvent.click(get('hilos-status-work-close'))
    await settled()
    expect(modals()).toHaveLength(0)
    expect(h.sent).toEqual([])
  })

  it('gives the one table focus to one window: another opener never drops a draft or takes the focus', async () => {
    const h = page()
    h.window([workRow(), workRow({ id: 8, title: 'Queue move' })])
    await h.open('create')
    expect(modals()).toHaveLength(1)
    expect(modals()[0]!.getAttribute('aria-label')).toBe('Schedule maintenance')
    fireEvent.change(get('hilos-status-work-title'), {
      target: { value: 'Unsaved draft' },
    })
    await h.open('edit')
    await h.open('cancel', 8)
    await h.open('create')
    expect(modals()).toHaveLength(1)
    expect(modals()[0]!.getAttribute('aria-label')).toBe('Schedule maintenance')
    expect((get('hilos-status-work-title') as HTMLInputElement).value).toBe(
      'Unsaved draft',
    )
    expect(h.focuses).toEqual([])
    fireEvent.click(get('hilos-status-work-close'))
    fireEvent.click(get('modal-confirm-discard'))
    await settled()
    expect(modals()).toHaveLength(0)

    await h.open('edit', 8)
    expect(h.focuses).toEqual([['hilos_status_works', 'hilosStatusWorks', '8']])
    expect(modals()[0]!.getAttribute('aria-label')).toBe(
      'Change scheduled maintenance',
    )
    expect((get('hilos-status-work-title') as HTMLInputElement).value).toBe(
      'Queue move',
    )
    await h.open('create')
    await h.open('cancel', 7)
    expect(modals()).toHaveLength(1)
    expect(modals()[0]!.getAttribute('aria-label')).toBe(
      'Change scheduled maintenance',
    )
    expect(find('hilos-status-work-cancel-form')).toBeNull()
    expect(h.focuses).toHaveLength(1)
    expect(h.sent).toEqual([])
  })

  it('leaves the mail mark to a real row echo after a cancellation succeeds', async () => {
    const h = page()
    h.window()
    const opener = await h.open('cancel')
    expect(get('hilos-status-work-cancel-target').textContent).toContain(
      'Database upgrade',
    )
    fireEvent.submit(get('hilos-status-work-cancel-form'))
    expect(h.sent).toEqual([
      {
        action: 'hilos_status_work_cancel',
        requestId: expect.any(String),
        data: {
          workId: 7,
          publicId: announcement.publicId,
          expectedVersion: 1,
        },
      },
    ])
    act(() => h.reply(true))
    await settled()
    expect(modals()).toHaveLength(0)
    expect(toasts()).toEqual(['Announcement canceled.'])
    expect(document.activeElement).toBe(opener)
    expect(get('hilos-status-work-mail-7', table()).textContent).toBe('—')
    h.update({ lastMailQueuedAt: 1_800_000_000_000 })
    expect(get('hilos-status-work-mail-7', table()).textContent).toBe(
      formatMoment(1_800_000_000_000, { format: MOMENT_FORMAT }),
    )
  })
})

describe('HilosStatusWorksPage lifecycle', () => {
  it('keeps one live generation through the StrictMode replay and releases it on unmount', async () => {
    const h = page(false, true)
    expect(h.registered.size).toBe(1)
    h.window([workRow(), workRow({ id: 8, title: 'Queue move' })])
    h.update({ title: 'Changed elsewhere' })
    expect(get('hilos-status-work-title-7', table()).textContent).toBe(
      'Changed elsewhere',
    )
    fireEvent.change(get('hilos-table-search'), {
      target: { value: 'upgrade' },
    })
    expect(h.viewports).toHaveLength(1)
    await h.open('edit', 8)
    expect(h.focuses).toHaveLength(1)
    expect(modals()).toHaveLength(1)
    expect((get('hilos-status-work-title') as HTMLInputElement).value).toBe(
      'Queue move',
    )
    fireEvent.click(get('hilos-status-work-close'))
    await settled()
    await h.open('create')
    expect(modals()).toHaveLength(1)
    expect(modals()[0]!.getAttribute('aria-label')).toBe('Schedule maintenance')
    h.unmount()
    expect(h.registered.size).toBe(0)
    expect(find('hilos-status-works-table')).toBeNull()
    expect(modals()).toHaveLength(0)
  })

  it('does not toast or revive a window after leaving the page', async () => {
    const h = page()
    h.window()
    await h.open('cancel')
    fireEvent.submit(get('hilos-status-work-cancel-form'))
    expect(h.sent).toHaveLength(1)
    act(() => {
      h.context.scopes.openPage(HilosPages.STATUS_COMPONENTS)
    })
    act(() => h.reply(true))
    await settled()
    expect(modals()).toHaveLength(0)
    expect(toasts()).toEqual([])
    expect(h.registered.size).toBe(0)
    h.window()
    expect(get('hilos-status-works-unavailable')).not.toBeNull()
    expect(find('hilos-status-works-table')).toBeNull()
  })

  it('lets a late frame after unmount reach nothing, and a remount binds a fresh generation', async () => {
    const h = page()
    h.window()
    h.unmount()
    h.window([])
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
    expect(h.registered.size).toBe(0)
    expect(find('hilos-status-works-table')).toBeNull()

    const again = render(
      <StrictMode>
        <HilosRouterContext.Provider value={h.router}>
          <HilosStatusWorksPage context={h.context} />
        </HilosRouterContext.Provider>
      </StrictMode>,
    )
    cleanups.push(again.unmount)
    expect(h.registered.size).toBe(1)
    h.window([workRow(), workRow({ id: 8 })])
    expect(table().querySelectorAll('tbody tr')).toHaveLength(2)
    expect(find('hilos-table-apply')).toBeNull()
    await h.open('cancel', 8)
    fireEvent.submit(get('hilos-status-work-cancel-form'))
    expect(h.sent).toHaveLength(1)
  })

  it('replaces the generation when the context changes, and its windows act on the new one', async () => {
    const h = page()
    h.window()
    const next = { ...h.context }
    act(() => {
      h.view.rerender(
        <HilosRouterContext.Provider value={h.router}>
          <HilosStatusWorksPage context={next} />
        </HilosRouterContext.Provider>,
      )
    })
    expect(h.registered.size).toBe(1)
    h.window([workRow(), workRow({ id: 8 })])
    expect(table().querySelectorAll('tbody tr')).toHaveLength(2)
    await h.open('cancel', 8)
    expect(h.focuses).toEqual([['hilos_status_works', 'hilosStatusWorks', '8']])
    fireEvent.submit(get('hilos-status-work-cancel-form'))
    expect(h.sent).toHaveLength(1)
    act(() => h.reply(true))
    await settled()
    expect(modals()).toHaveLength(0)
    expect(toasts()).toEqual(['Announcement canceled.'])
  })
})

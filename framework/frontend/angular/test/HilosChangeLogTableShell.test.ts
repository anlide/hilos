import {
  ChangeDetectionStrategy,
  Component,
  Input,
  signal,
} from '@angular/core'
import { TestBed } from '@angular/core/testing'
import {
  bindAdminAccess,
  createAppPageRouter,
  createSignal,
  HilosPages,
  pageIdentity,
  resolvePageTitle,
  ScopeManager,
  TableViewportController,
  type HilosRouter,
  type PageError,
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  CHANGE_LOG_TABLE_SECTION,
  type HilosChangeLogReceiptContext,
  type HilosChangeLogTableDetail,
} from '@hilos/core/admin/change-log'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { readTextWithoutAriaHidden } from '../../test/breadcrumb.js'
import { HilosChangeLogTableShell } from '../src/admin/changeLog/HilosChangeLogTableShell.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { LoadingButton } from '../src/LoadingButton.js'

const tabs = [
  { page: HilosPages.CHANGE_LOG_TABLE, label: 'Overview' },
  { page: HilosPages.CHANGE_LOG_TABLE_COLUMNS, label: 'Columns' },
  { page: HilosPages.CHANGE_LOG_TABLE_TRIGGERS, label: 'Triggers' },
  { page: HilosPages.CHANGE_LOG_TABLE_HISTORY, label: 'History' },
]
const table: HilosChangeLogTableDetail = {
  name: 'bot',
  owner: 'project',
  journaled: true,
  changesLast24h: 0,
  lastChangeAt: null,
  columns: [],
  triggers: [
    { name: 'insert', table: 'bot', event: 'INSERT', validFromMigration: 92 },
    { name: 'update', table: 'bot', event: 'UPDATE', validFromMigration: 7 },
  ],
}

/** The caller owns its table; the real shell must supply heading and view mode. */
@Component({
  selector: 'test-change-log-shell-host',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosChangeLogTableShell, HilosViewportTable, LoadingButton],
  template: `
    @if (shown()) {
      <hilos-change-log-table-shell [context]="context" [page]="page">
        <ng-template #pageBody>
          <hilos-viewport-table [controller]="controller" />
          <button
            hilosLoadingButton
            (click)="mutate()"
            data-id="child-mutation"
          >
            Change table
          </button>
        </ng-template>
      </hilos-change-log-table-shell>
    }
  `,
})
class ShellHost {
  @Input() context!: HilosChangeLogReceiptContext
  @Input() page: string = HilosPages.CHANGE_LOG_TABLE
  readonly shown = signal(true)
  readonly mutate = vi.fn()
  readonly controller = new TableViewportController({
    sendViewport: vi.fn(),
    resolve: (row) => ({ rowKey: row.rowKey, name: String(row.slots['name']) }),
    frame: {
      columns: [{ key: 'name', label: 'Name' }],
      bulkActions: [
        {
          key: 'change',
          label: 'Change table',
          refusalTitle: 'Could not change table',
          run: () => this.mutate(),
        },
      ],
    },
  })
}

const releases: Array<() => void> = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

function answerIdentity(scopes: ScopeManager) {
  const data = scopes.page()!.data
  data.set('pageLabel', 'Overview')
  data.set('pageHeadOwner', {
    page: HilosPages.CHANGE_LOG_TABLE,
    label: 'Tracked table',
    lead: 'One table.',
    icon: 'bi-table',
  })
  data.set('pageBreadcrumb', [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.CHANGE_LOG, label: 'Change Log' },
    { page: HilosPages.CHANGE_LOG_TABLES, label: 'Tracked tables' },
    { page: HilosPages.CHANGE_LOG_TABLE, label: 'Tracked table' },
  ])
  data.set('pageTabs', tabs)
}

/** Keep route, error and old page data independent to expose stale-answer races. */
function harness(page: string = HilosPages.CHANGE_LOG_TABLE, name = 'bot') {
  const scopes = new ScopeManager()
  scopes.openPage(page)
  const route = createSignal<PageRouteMatch>({
    page,
    params: { tableId: name, record: 'part,with%2Ccomma' },
    admin: true,
  })
  const error = createSignal<PageError | null>(null)
  const matcher = createAppPageRouter({})
  const router: HilosRouter = {
    currentRoute: route,
    currentPath: createSignal(matcher.path(page, route.get().params)!),
    currentTitle: createSignal(''),
    pageError: error,
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (key, params) => matcher.path(key, params),
    navigate: vi.fn(),
    replacePath: vi.fn(),
    clearPageError: vi.fn(),
    denyCurrentPage: vi.fn(),
    awaitPageAnswer: vi.fn(),
    onLeave: () => () => {},
    start: vi.fn(),
    stop: vi.fn(),
  }
  const context = {
    scopes,
    connection: {},
    actions: {},
  } as HilosChangeLogReceiptContext
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(ShellHost)
  fixture.componentRef.setInput('context', context)
  fixture.componentRef.setInput('page', page)
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  document.body.append(root)
  releases.push(() => root.remove())
  const act = (run: () => void) => {
    run()
    fixture.detectChanges()
  }
  const answer = (detail: HilosChangeLogTableDetail = { ...table, name }) => {
    scopes.page()!.data.set(CHANGE_LOG_TABLE_SECTION, detail)
    answerIdentity(scopes)
  }
  return { scopes, route, error, router, context, fixture, root, act, answer }
}

const el = (id: string) =>
  document.querySelector<HTMLElement>(`[data-id="${id}"]`)
const text = (node: Element) =>
  readTextWithoutAriaHidden(node).replace(/\s+/g, ' ')

describe('HilosChangeLogTableShell', () => {
  it.each(tabs)(
    'uses the permitted live name, breadcrumb and browser identity on $label',
    ({ page, label }) => {
      const name = 'table /?#%2F🌍'
      const h = harness(page, name)
      expect(el('hilos-page-head-skeleton')?.getAttribute('aria-hidden')).toBe(
        'true',
      )
      expect(h.root.querySelector('h1')).toBeNull()
      expect(h.root.querySelector('nav')).toBeNull()
      expect(el('child-mutation')).toBeNull()
      h.act(() => h.answer())
      expect([...h.root.querySelectorAll('h1')].map(text)).toEqual([name])
      const crumbs = h.root.querySelector('nav[aria-label="Breadcrumb"]')!
      expect(crumbs.querySelectorAll('li')).toHaveLength(4)
      expect(text(crumbs.querySelector('li:last-child')!)).toBe(name)
      expect(text(crumbs.querySelector('[aria-current="page"]')!)).toBe(name)
      expect(
        crumbs.querySelector('[aria-current="page"]')?.querySelector('a'),
      ).toBeNull()
      const identity = h.router.pageIdentity.get()!
      expect(identity.pageHeadOwner?.label).toBe(name)
      expect(identity.breadcrumb.at(-1)?.label).toBe(name)
      expect(
        resolvePageTitle(
          page,
          {},
          'Test app',
          identity.label,
          true,
          identity.pageHeadOwner?.label,
        ),
      ).toBe(`${name} · Test app`)
      const nav = h.root.querySelector('nav[aria-label="Table pages"]')!
      expect([...nav.querySelectorAll('a')].map(text)).toEqual([
        'Overview',
        'Columns',
        'Triggers',
        'History',
      ])
      expect(
        [...nav.querySelectorAll('[aria-current="page"]')].map(text),
      ).toEqual([label])
      expect(h.root.querySelector('[role="tablist"]')).toBeNull()
      for (const tab of tabs) {
        expect(el(`hilos-page-tab-${tab.page}`)?.getAttribute('href')).toBe(
          h.router.resolvePath(tab.page, h.route.get().params),
        )
      }
      expect(
        el('hilos-change-log-table-head-icon')?.getAttribute('aria-hidden'),
      ).toBe('true')
      expect(text(el('hilos-change-log-table-head-badges')!)).toBe(
        'Project Journaled',
      )
      expect(text(el('hilos-change-log-table-head-description')!)).toBe(
        'Journaled since migration 007',
      )
      h.act(() => h.answer({ ...table, name, triggers: [] }))
      expect(text(el('hilos-change-log-table-head-description')!)).toBe('')
    },
  )

  it('clears stale data on route, error, scope and context changes and unbinds on destroy', () => {
    const h = harness()
    h.act(() => h.answer())
    const expectCleared = () => {
      expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).not.toBe('bot')
      expect(el('hilos-change-log-table-head-badges')).toBeNull()
      expect(el('child-mutation')).toBeNull()
    }
    h.act(() => h.route.set({ ...h.route.get(), params: { tableId: 'other' } }))
    expectCleared()
    h.act(() => h.answer({ ...table, name: 'other' }))
    expect(text(h.root.querySelector('h1')!)).toBe('other')
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe('other')
    h.act(() => {
      h.route.set({ ...h.route.get(), params: { tableId: 'bot' } })
      h.answer()
    })
    expect(text(h.root.querySelector('h1')!)).toBe('bot')
    h.act(() =>
      h.error.set({
        httpCode: 403,
        errorCode: 'forbidden',
        title: 'Access denied',
        message: 'This page is closed.',
      }),
    )
    expectCleared()
    h.act(() => h.error.set(null))
    h.act(() =>
      h.route.set({
        ...h.route.get(),
        page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
      }),
    )
    expectCleared()
    h.act(() => {
      h.scopes.openPage(HilosPages.CHANGE_LOG_TABLE_HISTORY)
      h.answer()
    })
    expect(text(h.root.querySelector('h1')!)).toBe('bot')
    h.act(() => h.scopes.openPage(HilosPages.CHANGE_LOG_TABLE_HISTORY))
    expectCleared()
    expect(el('hilos-page-head-skeleton')).not.toBeNull()
    h.act(() => {
      h.scopes.openPage(HilosPages.CHANGE_LOG)
      h.route.set({ ...h.route.get(), page: HilosPages.CHANGE_LOG })
      h.answer()
    })
    expectCleared()
    h.act(() => {
      h.scopes.openPage(HilosPages.CHANGE_LOG_TABLE)
      h.route.set({ ...h.route.get(), page: HilosPages.CHANGE_LOG_TABLE })
      h.answer()
    })
    const replacement = new ScopeManager()
    replacement.openPage(HilosPages.CHANGE_LOG_TABLE)
    answerIdentity(replacement)
    h.act(() =>
      h.fixture.componentRef.setInput('context', {
        ...h.context,
        scopes: replacement,
      }),
    )
    expectCleared()
    h.act(() => h.answer({ ...table, name: 'old-context' }))
    expect(el('child-mutation')).toBeNull()
    h.act(() => replacement.page()!.data.set(CHANGE_LOG_TABLE_SECTION, table))
    expect(pageIdentity(replacement).get()?.pageHeadOwner?.label).toBe('bot')
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Tracked table',
    )
    expect(el('child-mutation')).not.toBeNull()
    h.act(() => h.fixture.componentInstance.shown.set(false))
    expect(pageIdentity(replacement).get()?.pageHeadOwner?.label).toBe(
      'Tracked table',
    )
    h.act(() =>
      replacement.page()!.data.set(CHANGE_LOG_TABLE_SECTION, {
        ...table,
        name: 'after-destroy',
      }),
    )
    expect(pageIdentity(replacement).get()?.pageHeadOwner?.label).toBe(
      'Tracked table',
    )
  })

  it('gives the real body table its h1 name and view mode to its controls', () => {
    const h = harness()
    h.scopes.session.data.set('adminViewMode', true)
    const release = bindAdminAccess(h.scopes)
    releases.push(release)
    h.act(() => h.answer())
    const heading = h.root.querySelector('h1')!
    const rendered = h.root.querySelector('table')!
    expect(heading.id).not.toBe('')
    expect(rendered.getAttribute('aria-labelledby')).toBe(heading.id)
    expect(h.root.querySelector('[id^="hilos-table-title-"]')).toBeNull()
    h.act(() => {
      const controller = h.fixture.componentInstance.controller
      controller.ingestWindow(
        [{ rowKey: 'one', slots: { name: 'bot' } }],
        1,
        true,
        null,
        null,
        25,
      )
      controller.selectRow('one', true)
    })
    const mutation = el('child-mutation') as HTMLButtonElement
    const bulk = el('hilos-table-bulk-change') as HTMLButtonElement
    for (const control of [mutation, bulk]) {
      expect(control.disabled).toBe(true)
      expect(control.getAttribute('aria-describedby')).toBe(
        'hilos-view-mode-strip-text',
      )
      h.act(() => control.click())
    }
    expect(h.fixture.componentInstance.mutate).not.toHaveBeenCalled()
    h.act(() => h.scopes.session.data.set('adminViewMode', false))
    expect(mutation.disabled).toBe(false)
    expect(bulk.disabled).toBe(false)
    h.act(() => h.scopes.session.data.set('adminViewMode', true))
    expect(mutation.disabled).toBe(true)
    expect(bulk.disabled).toBe(true)
    h.act(() => h.answer({ ...table, changesLast24h: 9 }))
    expect(h.root.querySelector('table')).toBe(rendered)
    expect(rendered.getAttribute('aria-labelledby')).toBe(heading.id)
    expect(mutation.disabled).toBe(true)
  })
})

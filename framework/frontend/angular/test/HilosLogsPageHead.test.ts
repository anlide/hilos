import type { Type } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  createAppPageRouter,
  createSignal,
  pageIdentity,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import { describe, expect, it } from 'vitest'

import { HilosLogsKeysPage } from '../src/admin/logs/HilosLogsKeysPage.js'
import { HilosLogsPage } from '../src/admin/logs/HilosLogsPage.js'
import { HilosLogsRotationsPage } from '../src/admin/logs/HilosLogsRotationsPage.js'
import { HilosLogsSettingsPage } from '../src/admin/logs/HilosLogsSettingsPage.js'
import { HilosLogsViewPage } from '../src/admin/logs/HilosLogsViewPage.js'
import { HilosLogsWorkersPage } from '../src/admin/logs/HilosLogsWorkersPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const PAGES: {
  name: string
  page: string
  component: Type<unknown>
}[] = [
  { name: 'Overview', page: HilosPages.LOGS, component: HilosLogsPage },
  { name: 'By key', page: HilosPages.LOGS_KEYS, component: HilosLogsKeysPage },
  {
    name: 'By worker',
    page: HilosPages.LOGS_WORKERS,
    component: HilosLogsWorkersPage,
  },
  {
    name: 'Rotations',
    page: HilosPages.LOGS_ROTATIONS,
    component: HilosLogsRotationsPage,
  },
  {
    name: 'Settings',
    page: HilosPages.LOGS_SETTINGS,
    component: HilosLogsSettingsPage,
  },
  { name: 'Viewer', page: HilosPages.LOGS_VIEW, component: HilosLogsViewPage },
]

/** Mount a real page with its answered identity and no body frames. */
function mount(component: Type<unknown>, page: string) {
  const connection = {
    on: () => () => {},
    sendAction: () => true,
    registerTableWindow: () => {},
    unregisterTableWindow: () => {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport: () => {},
    sendTableRowFocus: () => true,
    sendTableRendered: () => true,
    sendTableFacets: () => true,
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  scope.data.set('pageLabel', 'Logs')
  scope.data.set('pageLead', 'Journal overview.')
  scope.data.set('pageHeadOwner', {
    page: HilosPages.LOGS,
    label: 'Logs',
    lead: 'Journal overview.',
    icon: 'bi-journal-text',
  })
  scope.data.set('pageBreadcrumb', [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.LOGS, label: 'Logs' },
  ])
  scope.data.set(
    'pageTabs',
    PAGES.map(({ page, name }) => ({ page, label: name })),
  )
  scope.data.set('pageChildren', [])
  const matcher = createAppPageRouter({})
  const router: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(matcher.path(page) ?? ''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: matcher.path,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(component)
  fixture.componentRef.setInput('context', {
    connection,
    scopes,
    actions: new ActionLifecycle(connection),
  })
  fixture.detectChanges()

  return fixture
}

describe('Logs page head', () => {
  it.each(PAGES)(
    '$name names its tab navigation Logs pages',
    ({ page, component }) => {
      const fixture = mount(component, page)
      try {
        const root = fixture.nativeElement as HTMLElement
        expect(
          root.querySelector('nav[aria-label="Logs pages"]'),
        ).not.toBeNull()
      } finally {
        fixture.destroy()
      }
    },
  )
})

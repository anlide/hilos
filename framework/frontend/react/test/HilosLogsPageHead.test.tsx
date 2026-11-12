import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  createSignal,
} from '@hilos/core/base'
import type {
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
} from '@hilos/core/base'
import type { ReactNode } from 'react'

import { HilosLogsKeysPage } from '../src/admin/logs/HilosLogsKeysPage.js'
import { HilosLogsPage } from '../src/admin/logs/HilosLogsPage.js'
import { HilosLogsRotationsPage } from '../src/admin/logs/HilosLogsRotationsPage.js'
import { HilosLogsSettingsPage } from '../src/admin/logs/HilosLogsSettingsPage.js'
import { HilosLogsViewPage } from '../src/admin/logs/HilosLogsViewPage.js'
import { HilosLogsWorkersPage } from '../src/admin/logs/HilosLogsWorkersPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** The page answer that enables the shared Logs owner and its six tabs. */
const LOGS_IDENTITY: HilosPageIdentity = {
  label: 'Logs',
  lead: 'Journal overview.',
  breadcrumb: [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.LOGS, label: 'Logs' },
  ],
  children: [],
  pageHeadOwner: {
    page: HilosPages.LOGS,
    label: 'Logs',
    lead: 'Journal overview.',
    icon: 'bi-journal-text',
  },
  pageTabs: [
    { page: HilosPages.LOGS, label: 'Overview' },
    { page: HilosPages.LOGS_KEYS, label: 'By key' },
    { page: HilosPages.LOGS_WORKERS, label: 'By worker' },
    { page: HilosPages.LOGS_ROTATIONS, label: 'Rotations' },
    { page: HilosPages.LOGS_SETTINGS, label: 'Settings' },
    { page: HilosPages.LOGS_VIEW, label: 'Viewer' },
  ],
}

interface LogsPageTestContext {
  connection: HilosConnection
  scopes: ScopeManager
  actions: ActionLifecycle
}

interface LogsPageTestCase {
  name: string
  page: string
  mount: (context: LogsPageTestContext) => ReactNode
}

/** A no-frame connection for mounting each page's real shell. */
function makeContext(page: string): LogsPageTestContext {
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
  scopes.openPage(page)

  return {
    connection,
    scopes,
    actions: new ActionLifecycle(
      connection as unknown as ConstructorParameters<typeof ActionLifecycle>[0],
    ),
  }
}

/** The navigator answer carries the same Logs identity to each real page. */
function router(page: string): HilosRouter {
  return {
    currentRoute: createSignal({ page, params: {}, admin: true }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(LOGS_IDENTITY),
    dashboardSections: createSignal(undefined),
    resolvePath: (key) => `/hilos/${key}`,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

const PAGES: LogsPageTestCase[] = [
  {
    name: 'Overview',
    page: HilosPages.LOGS,
    mount: (context) => <HilosLogsPage context={context} />,
  },
  {
    name: 'By key',
    page: HilosPages.LOGS_KEYS,
    mount: (context) => <HilosLogsKeysPage context={context} />,
  },
  {
    name: 'By worker',
    page: HilosPages.LOGS_WORKERS,
    mount: (context) => <HilosLogsWorkersPage context={context} />,
  },
  {
    name: 'Rotations',
    page: HilosPages.LOGS_ROTATIONS,
    mount: (context) => <HilosLogsRotationsPage context={context} />,
  },
  {
    name: 'Settings',
    page: HilosPages.LOGS_SETTINGS,
    mount: (context) => <HilosLogsSettingsPage context={context} />,
  },
  {
    name: 'Viewer',
    page: HilosPages.LOGS_VIEW,
    mount: (context) => <HilosLogsViewPage context={context} />,
  },
]

describe('Logs page head', () => {
  afterEach(cleanup)

  it.each(PAGES)(
    '$name names its tab navigation Logs pages',
    ({ page, mount }) => {
      render(
        <HilosRouterContext.Provider value={router(page)}>
          {mount(makeContext(page))}
        </HilosRouterContext.Provider>,
      )

      expect(
        screen.getByRole('navigation', { name: 'Logs pages' }),
      ).toBeTruthy()
    },
  )
})

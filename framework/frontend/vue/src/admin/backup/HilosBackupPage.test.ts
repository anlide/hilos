import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  bindTimeZoneState,
  createSignal,
  hilosTimeZone,
} from '@hilos/core/base'
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
  HilosTableProgressFrame,
  PageRouteMatch,
  ProjectSignal,
  TableViewportController,
} from '@hilos/core/base'
import type {
  HilosBackupRow,
  HilosBackupsContext,
} from '@hilos/core/admin/backup'

import HilosBackupPage from './HilosBackupPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const BACKUPS_TABLE = 'hilosBackups'
const ARCHIVE_ID = '2026-09-10_03-00-00'

const BACKUP_IDENTITY: HilosPageIdentity = {
  label: 'Backups',
  lead: 'Database and file backup jobs and their history.',
  breadcrumb: [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.BACKUP, label: 'Backups' },
  ],
  children: [],
  pageHeadOwner: {
    page: HilosPages.BACKUP,
    label: 'Backups',
    lead: 'Database and file backup jobs and their history.',
    icon: 'bi-safe2',
  },
  pageTabs: [{ page: HilosPages.BACKUP, label: 'Overview' }],
}

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.BACKUP,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(BACKUP_IDENTITY),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      page === HilosPages.DASHBOARD ? '/hilos' : '/hilos/backup',
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

interface BackupSlot {
  createdAt: string | null
  env: string
  scope: string
  sizeBytes: number
  durationSeconds: number
  keep: boolean
  status: string
  finished: boolean | null
  checksumState: string
  shipState: string
  [key: string]: unknown
}

interface BackupFixture {
  id: string
  backup: BackupSlot
}

const ARCHIVE_FIXTURE: BackupFixture = {
  id: ARCHIVE_ID,
  backup: {
    createdAt: '2026-09-10T03:00:00.000000Z',
    env: 'dev',
    scope: 'full',
    sizeBytes: 1048576,
    durationSeconds: 5,
    keep: true,
    status: 'success',
    finished: true,
    checksumState: 'verified',
    shipState: 'none',
  },
}

function wireRow(item: BackupFixture): {
  rowKey: string
  slots: Record<string, unknown>
} {
  return {
    rowKey: item.id,
    slots: {
      backup: item.backup,
    },
  }
}

interface Dispatched {
  action: string
  payload: Record<string, unknown>
  settle: (result: ActionResult) => void
  refuse: (error: ActionError) => void
}

function makeActions(): {
  actions: ActionLifecycle
  dispatched: Dispatched[]
} {
  const dispatched: Dispatched[] = []
  const actions = {
    dispatch(action: string, payload: Record<string, unknown>): ActionHandle {
      let settle: (result: ActionResult) => void = () => {}
      let refuse: (error: ActionError) => void = () => {}
      const done = new Promise<ActionResult>((resolve, reject) => {
        settle = resolve
        refuse = reject
      })
      dispatched.push({ action, payload, settle, refuse })

      return {
        requestId: String(dispatched.length),
        loading: createSignal(false),
        done,
      }
    },
  } as unknown as ActionLifecycle

  return { actions, dispatched }
}

interface SeededOptions {
  backupRestore?: { uiEnabled: boolean; targetEnv?: string }
  backupReopen?: { offered: boolean }
  progress?: HilosTableProgressFrame[]
}

function seededContext(
  initial: BackupFixture[] = [ARCHIVE_FIXTURE],
  actions: ActionLifecycle = makeActions().actions,
  options: SeededOptions = {},
): {
  context: HilosBackupsContext
  emit: (event: string, data: unknown, type?: string) => void
} {
  const rows = initial.slice()
  const scopes = new ScopeManager()
  const page = scopes.openPage(HilosPages.BACKUP)
  if (options.backupRestore) {
    page.data.set('backupRestore', options.backupRestore)
  }
  if (options.backupReopen) {
    page.data.set('backupReopen', options.backupReopen)
  }

  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (
    pageKey: string = HilosPages.BACKUP,
    tableKey: string = BACKUPS_TABLE,
  ): void => {
    const data = {
      page: pageKey,
      tableKey,
      rows: rows.map(wireRow),
      totalCount: rows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 10,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }

  const listeners = new Map<
    string,
    Set<(signal: { data: unknown; type?: string }) => void>
  >()
  const emit = (event: string, data: unknown, type?: string): void => {
    for (const listener of listeners.get(event) ?? []) listener({ data, type })
  }
  const connection = {
    registerTableWindow(): void {
      emit(
        'projectSignal',
        {
          page: HilosPages.BACKUP,
          payload: {
            windows: {
              [BACKUPS_TABLE]: {
                rows: rows.map(wireRow),
                totalCount: rows.length,
                totalExact: true,
                firstAnchor: null,
                lastAnchor: null,
                sort: [{ field: 'createdAt', direction: 'desc' }],
                limit: 10,
                progress: options.progress ?? [],
              },
            },
          },
        },
        'page_response',
      )
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(pageKey: string, tableKey: string): boolean {
      serveWindow(pageKey, tableKey)

      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    sendTableFacets(): boolean {
      return true
    },
    sendTableRowFocus(): boolean {
      return true
    },
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)

        return () => windowListeners.delete(listener)
      }
      if (event === 'tableViewportDelta') {
        deltaListeners.add(listener)

        return () => deltaListeners.delete(listener)
      }

      let eventListeners = listeners.get(event)
      if (!eventListeners) {
        eventListeners = new Set()
        listeners.set(event, eventListeners)
      }
      eventListeners.add(listener)

      return () => eventListeners.delete(listener)
    },
  }

  return {
    emit,
    context: {
      connection: connection as unknown as HilosBackupsContext['connection'],
      scopes,
      actions,
    },
  }
}

const mounted: ReturnType<typeof mount>[] = []
const cleanups: (() => void)[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  for (const cleanup of cleanups.splice(0)) cleanup()
  vi.restoreAllMocks()
  vi.useRealTimers()
})

async function mountPage(
  context: HilosBackupsContext,
  pageRouter: HilosRouter = router(),
) {
  const wrapper = mount(HilosBackupPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: pageRouter } },
  })
  mounted.push(wrapper)
  await nextTick()

  return wrapper
}

async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

/** Drive the production zone binding while keeping real Intl output in a fixed locale. */
function readerTimeZone() {
  const DateTimeFormat = Intl.DateTimeFormat
  vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(
    function (_locales, options) {
      return new DateTimeFormat('en-US', options)
    },
  )
  const previousZone = hilosTimeZone.get()
  const listeners = new Map<string, (signal: ProjectSignal) => void>()
  const connection = {
    projectTimeZone: 'UTC',
    on(event: string, listener: (signal: ProjectSignal) => void) {
      listeners.set(event, listener)
      return () => listeners.delete(event)
    },
  } as unknown as HilosConnection
  const release = bindTimeZoneState(connection)
  const setZone = (timeZone: string): void => {
    listeners.get('projectSignal')!({
      type: 'handshake_response',
      data: { entities: { currentUser: { id: 1, timeZone } } },
    } as ProjectSignal)
  }
  cleanups.push(() => {
    setZone(previousZone)
    release()
  })
  setZone('UTC')
  return setZone
}

describe('HilosBackupPage section presentation', () => {
  it('moves from the section skeleton to one shared head and named table', async () => {
    const { context } = seededContext()
    const identity = createSignal<HilosPageIdentity | undefined>(undefined)
    const wrapper = await mountPage(context, {
      ...router(),
      pageIdentity: identity,
    })
    const skeleton = wrapper.get('[data-id="hilos-page-head-skeleton"]')
    expect(skeleton.attributes('aria-hidden')).toBe('true')
    expect(skeleton.find('.rounded-circle').exists()).toBe(true)
    expect(wrapper.find('h1').exists()).toBe(false)
    expect(
      wrapper.find('[data-id="hilos-admin-title-skeleton"]').exists(),
    ).toBe(false)
    expect(wrapper.find('nav').exists()).toBe(false)

    identity.set(BACKUP_IDENTITY)
    await settled()

    expect(wrapper.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      false,
    )
    expect(wrapper.findAll('h1')).toHaveLength(1)
    const heading = wrapper.get('h1')
    expect(heading.text()).toBe('Backups')
    expect(wrapper.findAll('.bi-safe2')).toHaveLength(1)
    expect(wrapper.text()).toContain(
      'Database and file backup jobs and their history.',
    )
    expect(wrapper.get('table').attributes('aria-labelledby')).toBe(
      heading.attributes('id'),
    )
    expect(wrapper.get('[role="list"]').attributes('aria-labelledby')).toBe(
      heading.attributes('id'),
    )
    const crumbs = wrapper.get('nav[aria-label="Breadcrumb"]')
    expect(crumbs.get('a').text()).toBe('Hilos')
    expect(crumbs.get('a').attributes('href')).toBe('/hilos')
    expect(crumbs.get('[aria-current="page"]').text()).toBe('Backups')
    expect(crumbs.findAll('a')).toHaveLength(1)
    expect(wrapper.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-admin-title"]').exists()).toBe(false)
  })

  it('points to the Circle tab with the matching explanation', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions)
    const pageRouter = router()
    const navigate = vi.spyOn(pageRouter, 'navigate')
    const wrapper = await mountPage(context, pageRouter)
    const pointer = wrapper.get('[data-id="hilos-backup-circle-pointer"]')
    expect(pointer.get('.fw-semibold').text()).toBe(
      'Who can check the system after a restore',
    )
    expect(pointer.get('.small').text()).toBe(
      'The verifier circle is kept at /hilos/maintenance/circle. Set it up before you press Restore: ' +
        'only members with a tab open at that moment are let into the verification window.',
    )
    const link = pointer.get('a[data-id="hilos-backup-circle-pointer-open"]')
    expect(link.attributes('href')).toBe('/hilos/maintenance/circle')
    expect(link.text()).toBe('Open Maintenance')
    expect(link.get('i').attributes('aria-hidden')).toBe('true')
    await link.trigger('click')
    expect(navigate).toHaveBeenCalledExactlyOnceWith(
      '/hilos/maintenance/circle',
    )
    expect(dispatched).toHaveLength(0)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })
})

describe('HilosBackupPage run caption', () => {
  const title = 'Backup 2026-10-28_14-20-00 · prod · full'
  const run: HilosTableProgressFrame = {
    scope: 'table',
    progressKey: 'backup-run',
    current: 62,
    total: 100,
    detail: {
      backupId: '2026-10-28_14-20-00',
      env: 'prod',
      scope: 'full',
      phase: 'archiving',
      remainingSeconds: 40,
    },
  }

  it.each([
    [run, 'archiving · 62% · ~40s left'],
    [
      { ...run, total: undefined, detail: { ...run.detail, phase: 'dumping' } },
      'dumping',
    ],
    [
      {
        ...run,
        current: 99,
        detail: { ...run.detail, phase: 'publishing', remainingSeconds: 0 },
      },
      'publishing · 99% · taking longer than usual',
    ],
  ])(
    'renders the initial window and live caption: %s',
    async (initial, tail) => {
      const { context, emit } = seededContext([ARCHIVE_FIXTURE], undefined, {
        progress: [initial],
      })
      const wrapper = await mountPage(context)
      const visible = wrapper.get(
        '[data-id="hilos-table-progress"] .text-truncate',
      )
      const announcement = wrapper.get('[data-id="hilos-table-live-status"]')
      expect(visible.get('strong').text()).toBe(title)
      expect(visible.text()).toBe(`${title} — ${tail}`)
      expect(announcement.text()).toBe(`${title} — ${tail}`)
      expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
      expect(announcement.classes()).toContain('visually-hidden')
      expect(visible.find('button').exists()).toBe(false)
      expect(wrapper.findAll('table tbody tr')).toHaveLength(1)
      expect(
        wrapper.find('[data-id^="hilos-table-progress-row-"]').exists(),
      ).toBe(false)

      emit('tableProgress', {
        page: HilosPages.BACKUP,
        tableKey: BACKUPS_TABLE,
        ...initial,
        detail: {
          ...initial.detail,
          backupId: '2026-10-28_14-21-00',
          env: 'dev',
        },
      })
      await settled()
      const updatedTitle = 'Backup 2026-10-28_14-21-00 · dev · full'
      const updated = `${updatedTitle} — ${tail}`
      expect(visible.text()).toBe(updated)
      expect(visible.get('strong').text()).toBe(updatedTitle)
      expect(announcement.text()).toBe(updated)

      emit('tableProgress', {
        page: HilosPages.BACKUP,
        tableKey: BACKUPS_TABLE,
        ...run,
        ended: true,
      })
      await settled()
      expect(wrapper.find('[data-id="hilos-table-progress"]').exists()).toBe(
        false,
      )
      expect(announcement.text()).toBe('')
      expect(wrapper.text()).not.toContain(title)
      expect(wrapper.text()).not.toContain(updatedTitle)
      expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    },
  )

  it('renders only the tail when identity is absent and clears on an idle window', async () => {
    const { context, emit } = seededContext([ARCHIVE_FIXTURE], undefined, {
      progress: [{ ...run, detail: { phase: 'dumping' }, total: undefined }],
    })
    const wrapper = await mountPage(context)
    const visible = wrapper.get(
      '[data-id="hilos-table-progress"] .text-truncate',
    )
    expect(visible.find('strong').exists()).toBe(false)
    expect(visible.text()).toBe('dumping')
    expect(wrapper.get('[data-id="hilos-table-live-status"]').text()).toBe(
      'dumping',
    )
    emit(
      'projectSignal',
      {
        page: HilosPages.BACKUP,
        payload: {
          windows: {
            [BACKUPS_TABLE]: {
              rows: [wireRow(ARCHIVE_FIXTURE)],
              totalCount: 1,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 10,
              sort: [],
              progress: [],
            },
          },
        },
      },
      'page_response',
    )
    await settled()
    expect(wrapper.find('[data-id="hilos-table-progress"]').exists()).toBe(
      false,
    )
    expect(wrapper.get('[data-id="hilos-table-live-status"]').text()).toBe('')
  })
})

describe('HilosBackupPage failed restore', () => {
  it.each([
    [true, false],
    [false, false],
    [true, true],
    [false, true],
  ])(
    'keeps databaseTouched=%s and rehydrateComplete=%s independent',
    async (databaseTouched, rehydrateComplete) => {
      const { context, emit } = seededContext()
      const wrapper = await mountPage(context)
      emit(
        'projectSignal',
        {
          running: false,
          backupId: ARCHIVE_ID,
          scope: 'full',
          phase: 'failed',
          phaseStartedAt: null,
          startedAt: null,
          finishedAt: null,
          outcome: 'error',
          failureReason: 'tar: unexpected end of file',
          estimatedSeconds: null,
          rehydrateComplete,
          rehydrateProblems: [],
          databaseTouched,
        },
        'backup_restore_progress',
      )
      await settled()
      const panel = wrapper.get('[data-id="hilos-backup-restore-phase"]')
      expect(panel.classes()).toContain('alert-danger')
      expect(panel.get('.fw-semibold').text()).toBe(
        `Restore ${ARCHIVE_ID} · failed`,
      )
      const lines = panel.findAll('.small > div').map((line) => line.text())
      expect(lines).toEqual([
        'tar: unexpected end of file',
        ...(databaseTouched
          ? ['The database was already being replaced when this failed.']
          : []),
        ...(!rehydrateComplete
          ? [
              'The system stays closed: not every process re-read the replaced database. ' +
                'Open it in Maintenance (/hilos/maintenance) or from the CLI with php cli.php protected-mode:open.',
            ]
          : []),
      ])
      expect(panel.find('[role="progressbar"]').exists()).toBe(false)
      expect(panel.findAll('code').map((code) => code.text())).toEqual(
        rehydrateComplete
          ? []
          : ['/hilos/maintenance', 'php cli.php protected-mode:open'],
      )
    },
  )
})

describe('HilosBackupPage reader-zone moments', () => {
  it('redraws both row projections and an open outcome without another backup frame', async () => {
    vi.useFakeTimers()
    const setZone = readerTimeZone()
    setZone('America/Los_Angeles')
    const fixture: BackupFixture = {
      id: ARCHIVE_ID,
      backup: {
        ...ARCHIVE_FIXTURE.backup,
        createdAt: '2026-01-01T00:30:05.123456Z',
        verifiedAt: '2026-01-02T00:20:06.123456Z',
        shipState: 'shipped',
        shippedAt: '2026-01-03T00:10:07.123456Z',
        restoreOutcome: 'success',
        restoreFinishedAt: '2026-01-04T00:05:08.123456Z',
      },
    }
    const { context } = seededContext([fixture])
    const wrapper = await mountPage(context)
    // The initial rows arrive in page_response. Hold later viewport answers:
    // a zone change must redraw the moments already held, without a server frame.
    const viewport = vi
      .spyOn(context.connection, 'sendTableViewport')
      .mockReturnValue(true)
    // Test Utils loses the generic SFC's prop type at findComponent.
    const { controller } = wrapper
      .findComponent(HilosViewportTable)
      .props() as { controller: TableViewportController<HilosBackupRow> }
    const original = controller.rows.get()[0]?.row
    const projections = [
      wrapper.get(`table [data-id="hilos-table-row-${ARCHIVE_ID}"]`),
      wrapper.get(`[data-id="hilos-table-card-${ARCHIVE_ID}"]`),
    ]
    for (const row of projections) {
      expect(row.get('[data-id="hilos-backup-created-at"]').text()).toBe(
        'Dec 31, 2025, 4:30:05 PM',
      )
      expect(row.get('[data-id="hilos-backup-checksum"]').text()).toBe(
        '✓ Jan 1, 2026',
      )
      expect(row.get('[data-id="hilos-backup-shipping"]').text()).toBe(
        '✓ Jan 2, 2026',
      )
      expect(row.text()).toContain('5s')
      expect(row.text()).toContain('success')
    }
    await projections[0]!
      .get(`[data-id="hilos-backup-restore-outcome-${ARCHIVE_ID}"]`)
      .trigger('click')
    await settled()
    const outcome = document.querySelector(
      '[data-id="hilos-backup-restore-outcome-finished"]',
    )!
    const modal = outcome.closest('[role="dialog"]')
    expect(outcome.textContent?.trim()).toBe(
      'Finished Jan 3, 2026, 4:05:08 PM · success',
    )

    setZone('Europe/Kyiv')
    await settled()
    for (const row of projections) {
      expect(row.get('[data-id="hilos-backup-created-at"]').text()).toBe(
        'Jan 1, 2026, 2:30:05 AM',
      )
      expect(row.get('[data-id="hilos-backup-checksum"]').text()).toBe(
        '✓ Jan 2, 2026',
      )
      expect(row.get('[data-id="hilos-backup-shipping"]').text()).toBe(
        '✓ Jan 3, 2026',
      )
      expect(row.text()).toContain('5s')
      expect(row.text()).toContain('success')
    }
    expect(outcome.textContent?.trim()).toBe(
      'Finished Jan 4, 2026, 2:05:08 AM · success',
    )
    expect(outcome.closest('[role="dialog"]')).toBe(modal)
    expect(viewport).toHaveBeenCalledTimes(1)
    expect(controller.rows.get()[0]?.row).toStrictEqual(original)
    expect(original).toMatchObject({
      createdAt: '2026-01-01T00:30:05.123456Z',
      verifiedAt: '2026-01-02T00:20:06.123456Z',
      shippedAt: '2026-01-03T00:10:07.123456Z',
      restoreFinishedAt: '2026-01-04T00:05:08.123456Z',
    })
  })

  it.each([null, 'malformed', '2026-02-30T00:00:00.000000Z'])(
    'keeps the missing markers for an unrepresentable moment: %s',
    async (moment) => {
      readerTimeZone()
      const { context } = seededContext([
        {
          id: ARCHIVE_ID,
          backup: {
            ...ARCHIVE_FIXTURE.backup,
            createdAt: moment,
            verifiedAt: moment,
            shipState: 'shipped',
            shippedAt: moment,
            restoreOutcome: 'success',
            restoreFinishedAt: moment,
          },
        },
      ])
      const wrapper = await mountPage(context)
      const row = wrapper.get(`table [data-id="hilos-table-row-${ARCHIVE_ID}"]`)
      expect(row.get('[data-id="hilos-backup-created-at"]').text()).toBe('—')
      expect(row.get('[data-id="hilos-backup-checksum"]').text()).toBe('✓')
      expect(row.get('[data-id="hilos-backup-shipping"]').text()).toBe('✓')
      await row
        .get(`[data-id="hilos-backup-restore-outcome-${ARCHIVE_ID}"]`)
        .trigger('click')
      await settled()
      expect(
        document
          .querySelector('[data-id="hilos-backup-restore-outcome-finished"]')
          ?.textContent?.trim(),
      ).toBe('Finished — · success')
    },
  )

  it.each([
    ['present', 'none', 'present', '—'],
    ['mismatch', 'failed', 'MISMATCH', 'FAILED'],
    ['none', 'pending', '—', 'pending'],
  ])(
    'preserves checksum %s and shipping %s markers',
    async (checksumState, shipState, checksum, shipping) => {
      const setZone = readerTimeZone()
      const { context } = seededContext([
        {
          id: ARCHIVE_ID,
          backup: { ...ARCHIVE_FIXTURE.backup, checksumState, shipState },
        },
      ])
      const wrapper = await mountPage(context)
      vi.spyOn(context.connection, 'sendTableViewport').mockReturnValue(true)
      setZone('America/Los_Angeles')
      await settled()
      const row = wrapper.get(`table [data-id="hilos-table-row-${ARCHIVE_ID}"]`)
      expect(row.get('[data-id="hilos-backup-checksum"]').text()).toBe(checksum)
      expect(row.get('[data-id="hilos-backup-shipping"]').text()).toBe(shipping)
    },
  )
})

describe('HilosBackupPage with the free texts hidden (HIL-1260)', () => {
  const HIDDEN = { _hidden: true }
  const FAILED_ID = '2026-09-11_03-00-00'
  const SHIP_FAILED_ID = '2026-09-12_03-00-00'
  const LEGACY_FAILED_ID = '2026-09-13_03-00-00'

  /** The modal standing on the page, or null. */
  function modal(): HTMLElement | null {
    return document.querySelector('[data-id="modal"]')
  }

  it('keeps the button of a hidden reason and opens the mark in place of the text', async () => {
    const { context } = seededContext([
      {
        id: FAILED_ID,
        backup: {
          ...ARCHIVE_FIXTURE.backup,
          status: 'error',
          finished: null,
          failureReason: HIDDEN,
        },
      },
      {
        id: LEGACY_FAILED_ID,
        backup: {
          ...ARCHIVE_FIXTURE.backup,
          status: 'failed',
          finished: null,
        },
      },
      ARCHIVE_FIXTURE,
      {
        id: SHIP_FAILED_ID,
        backup: {
          ...ARCHIVE_FIXTURE.backup,
          shipState: 'failed',
          shipError: HIDDEN,
        },
      },
    ])
    await mountPage(context)

    // A failed backup with finished: null is refused and tinted danger on both projections.
    const failedRow = document.querySelector<HTMLTableRowElement>(
      `table [data-id="hilos-table-row-${FAILED_ID}"]`,
    )
    expect(failedRow?.classList.contains('table-danger')).toBe(true)
    expect(failedRow?.classList.contains('table-success')).toBe(false)
    expect(failedRow?.classList.contains('table-warning')).toBe(false)

    const failedCard = document.querySelector<HTMLElement>(
      `[data-id="hilos-table-card-${FAILED_ID}"]`,
    )
    expect(failedCard?.classList.contains('border-danger')).toBe(true)
    expect(failedCard?.classList.contains('border-success')).toBe(false)
    expect(failedCard?.classList.contains('border-warning')).toBe(false)

    expect(failedRow?.textContent).toContain('error')
    expect(failedCard?.textContent).toContain('error')

    // A legacy failure without a failureReason stays danger-tinted on both projections.
    const legacyRow = document.querySelector<HTMLTableRowElement>(
      `table [data-id="hilos-table-row-${LEGACY_FAILED_ID}"]`,
    )
    expect(legacyRow?.classList.contains('table-danger')).toBe(true)
    expect(legacyRow?.classList.contains('table-success')).toBe(false)
    expect(legacyRow?.classList.contains('table-warning')).toBe(false)

    const legacyCard = document.querySelector<HTMLElement>(
      `[data-id="hilos-table-card-${LEGACY_FAILED_ID}"]`,
    )
    expect(legacyCard?.classList.contains('border-danger')).toBe(true)
    expect(legacyCard?.classList.contains('border-success')).toBe(false)
    expect(legacyCard?.classList.contains('border-warning')).toBe(false)

    expect(legacyRow?.textContent).toContain('failed')
    expect(legacyCard?.textContent).toContain('failed')

    // An ordinary successful backup has finished: true and wears no danger tint.
    const okRow = document.querySelector<HTMLTableRowElement>(
      `table [data-id="hilos-table-row-${ARCHIVE_ID}"]`,
    )
    expect(okRow?.classList.contains('table-danger')).toBe(false)

    const okCard = document.querySelector<HTMLElement>(
      `[data-id="hilos-table-card-${ARCHIVE_ID}"]`,
    )
    expect(okCard?.classList.contains('border-danger')).toBe(false)

    // A ship failure on a successful archive does not color the whole record.
    const shipFailedRow = document.querySelector<HTMLTableRowElement>(
      `table [data-id="hilos-table-row-${SHIP_FAILED_ID}"]`,
    )
    expect(shipFailedRow?.classList.contains('table-danger')).toBe(false)

    const shipFailedCard = document.querySelector<HTMLElement>(
      `[data-id="hilos-table-card-${SHIP_FAILED_ID}"]`,
    )
    expect(shipFailedCard?.classList.contains('border-danger')).toBe(false)
    ;(
      document.querySelector(
        `table [data-id="hilos-backup-details-${FAILED_ID}"]`,
      ) as HTMLElement
    ).click()
    await settled()
    expect(
      modal()?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
    expect(
      modal()?.querySelector('[data-id="hilos-backup-details-text"]'),
    ).toBeNull()
    ;(
      document.querySelector(
        '[data-id="hilos-backup-details-close"]',
      ) as HTMLElement
    ).click()
    await settled()
    ;(
      document.querySelector(
        `table [data-id="hilos-backup-ship-why-${SHIP_FAILED_ID}"]`,
      ) as HTMLElement
    ).click()
    await settled()
    expect(
      modal()?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
    expect(
      modal()?.querySelector('[data-id="hilos-backup-ship-error-text"]'),
    ).toBeNull()
  })
})

describe('HilosBackupPage why a restore is blocked', () => {
  const RESTORE_GATE = { backupRestore: { uiEnabled: true, targetEnv: 'dev' } }
  const REFUSED = {
    restoreMigrationDecision: 'refuse',
    restoreMigrationNotice:
      'This archive was taken at migration 0102; this code stops at 0101.',
  }
  const run: HilosTableProgressFrame = {
    scope: 'table',
    progressKey: 'backup-run',
    current: 10,
    total: 100,
    detail: {
      backupId: '2026-10-28_14-20-00',
      env: 'dev',
      scope: 'full',
      phase: 'archiving',
    },
  }

  /** The modal standing on the page, or null. */
  function modal(): HTMLElement | null {
    return document.querySelector('[data-id="modal"]')
  }

  /** One control of the archive's table row, or null. */
  function inRow(dataId: string): HTMLButtonElement | null {
    return document.querySelector<HTMLButtonElement>(
      `table [data-id="hilos-table-row-${ARCHIVE_ID}"] [data-id="${dataId}"]`,
    )
  }

  it.each([
    [
      'out of reach before a checksum mismatch',
      { holderNode: 'node-2', checksumState: 'mismatch', ...REFUSED },
      [],
      'Stored on node node-2 - the backup agent runs elsewhere, so this archive cannot be reached from here',
    ],
    [
      'a checksum mismatch before a migration refusal',
      { checksumState: 'mismatch', ...REFUSED },
      [],
      'This archive does not match its recorded checksum',
    ],
    [
      'a migration refusal in its notice before a busy subsystem',
      REFUSED,
      [run],
      REFUSED.restoreMigrationNotice,
    ],
    [
      'a migration refusal without a notice',
      { restoreMigrationDecision: 'refuse' },
      [],
      'This archive was taken on newer code; there is no downgrade path',
    ],
    [
      'a busy subsystem',
      {},
      [run],
      'The backup subsystem is busy; wait for the current run to end',
    ],
  ])(
    'opens %s in the shared dialog and sends nothing',
    async (_case, slot, progress, reason) => {
      const { actions, dispatched } = makeActions()
      const { context } = seededContext(
        [{ id: ARCHIVE_ID, backup: { ...ARCHIVE_FIXTURE.backup, ...slot } }],
        actions,
        { ...RESTORE_GATE, progress },
      )
      await mountPage(context)

      expect(inRow(`hilos-backup-restore-${ARCHIVE_ID}`)?.disabled).toBe(true)
      const opener = inRow('hilos-action-reason')
      expect(opener).not.toBeNull()
      expect(opener?.disabled).toBe(false)
      expect(opener?.getAttribute('aria-label')).toBe(
        'Why this backup cannot be restored',
      )
      expect(opener?.getAttribute('title')).toBe(reason)
      opener!.focus()
      opener!.click()
      await settled()

      const dialog = modal()
      expect(dialog?.getAttribute('aria-label')).toBe(
        `Cannot restore · ${ARCHIVE_ID}`,
      )
      expect(document.activeElement).toBe(dialog)
      expect(
        dialog
          ?.querySelector('[data-id="hilos-action-reason-body"]')
          ?.textContent?.trim(),
      ).toBe(reason)
      dialog!.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
      await settled()
      expect(modal()).toBeNull()
      expect(document.activeElement).toBe(opener)
      expect(dispatched).toHaveLength(0)
    },
  )

  it('keeps the opener of a hidden migration notice and shows the mark', async () => {
    const { context } = seededContext(
      [
        {
          id: ARCHIVE_ID,
          backup: {
            ...ARCHIVE_FIXTURE.backup,
            restoreMigrationDecision: 'refuse',
            restoreMigrationNotice: { _hidden: true },
          },
        },
      ],
      undefined,
      RESTORE_GATE,
    )
    await mountPage(context)

    expect(inRow(`hilos-backup-restore-${ARCHIVE_ID}`)?.disabled).toBe(true)
    const opener = inRow('hilos-action-reason')
    expect(opener).not.toBeNull()
    expect(opener?.hasAttribute('title')).toBe(false)
    opener!.click()
    await settled()
    const body = modal()?.querySelector('[data-id="hilos-action-reason-body"]')
    expect(
      body?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
    expect(body?.textContent).not.toContain('newer code')
  })

  it('gives a restorable archive a live restore and no opener', async () => {
    const { context } = seededContext(
      [ARCHIVE_FIXTURE],
      undefined,
      RESTORE_GATE,
    )
    await mountPage(context)

    expect(inRow(`hilos-backup-restore-${ARCHIVE_ID}`)?.disabled).toBe(false)
    expect(document.querySelector('[data-id="hilos-action-reason"]')).toBeNull()
  })
})

describe('HilosBackupPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  function bindSession() {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    const scopes = new ScopeManager()
    bindSessionScope(connection, scopes)
    releases.push(bindAdminAccess(scopes))

    return {
      handshake(
        user: { id: number; admin: boolean } | null,
        viewMode: boolean,
      ): void {
        const signal = {
          kind: 'project',
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: user === null ? null : { ...user, name: 'Olena' },
            },
            data: { adminViewMode: viewMode },
          },
          envelope: {},
        } as unknown as ProjectSignal
        for (const listener of listeners) {
          listener(signal)
        }
      },
    }
  }

  it('a viewer opens the create dialog, may pick a scope and has nothing to start it with', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions)
    const wrapper = await mountPage(context)

    const mainActionButton = wrapper.get<HTMLButtonElement>(
      '[data-id="hilos-table-main-action"]',
    )
    expect(mainActionButton.element.disabled).toBe(false)
    mainActionButton.element.click()
    await nextTick()

    const scopeSelect = document.querySelector<HTMLSelectElement>(
      '[data-id="hilos-backup-create-scope"]',
    )
    expect(scopeSelect).not.toBeNull()
    expect(scopeSelect?.disabled).toBe(false)
    scopeSelect!.value = 'schema-only'
    scopeSelect!.dispatchEvent(new Event('change'))
    await nextTick()
    expect(scopeSelect?.value).toBe('schema-only')

    const createConfirm = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-backup-create-confirm"]',
    )
    expect(createConfirm).not.toBeNull()
    expect(createConfirm?.disabled).toBe(true)
    expect(createConfirm?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    createConfirm?.click()
    await settled()
    expect(dispatched).toHaveLength(0)

    const cancelButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-backup-create-cancel"]',
    )
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    cancelButton?.click()
    await settled()
    expect(
      document.querySelector('[data-id="hilos-backup-create-scope"]'),
    ).toBeNull()
  })

  it('a viewer opens the delete dialog over a row and has nothing to delete with', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions)
    await mountPage(context)

    const trashButton = document.querySelector<HTMLButtonElement>(
      `table [data-id="hilos-backup-delete-${ARCHIVE_ID}"]`,
    )
    expect(trashButton).not.toBeNull()
    expect(trashButton?.disabled).toBe(false)
    trashButton?.click()
    await nextTick()

    const deleteConfirm = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-backup-delete-confirm"]',
    )
    expect(deleteConfirm).not.toBeNull()
    expect(deleteConfirm?.disabled).toBe(true)
    expect(deleteConfirm?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    deleteConfirm?.click()
    await settled()
    expect(dispatched).toHaveLength(0)

    const cancelButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-backup-delete-cancel"]',
    )
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    cancelButton?.click()
    await settled()
    expect(
      document.querySelector('[data-id="hilos-backup-delete-confirm"]'),
    ).toBeNull()
  })

  it('a viewer finds the keep switch standing where the server put it', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions)
    await mountPage(context)

    const keepSwitch = document.querySelector<HTMLInputElement>(
      `table [data-id="hilos-backup-keep-${ARCHIVE_ID}"]`,
    )
    expect(keepSwitch).not.toBeNull()
    expect(keepSwitch?.disabled).toBe(true)
    expect(keepSwitch?.checked).toBe(true)
    expect(keepSwitch?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    keepSwitch?.click()
    await settled()
    expect(dispatched).toHaveLength(0)
  })

  it('a viewer opens the restore dialog, types the archive id and has nothing to restore with', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions, {
      backupRestore: { uiEnabled: true, targetEnv: 'dev' },
    })
    await mountPage(context)

    const restoreButton = document.querySelector<HTMLButtonElement>(
      `table [data-id="hilos-backup-restore-${ARCHIVE_ID}"]`,
    )
    expect(restoreButton).not.toBeNull()
    expect(restoreButton?.disabled).toBe(false)
    restoreButton?.click()
    await nextTick()

    const idInput = document.querySelector<HTMLInputElement>(
      '[data-id="hilos-backup-restore-id"]',
    )
    expect(idInput).not.toBeNull()
    expect(idInput?.disabled).toBe(false)
    idInput!.value = ARCHIVE_ID
    idInput!.dispatchEvent(new Event('input'))
    await nextTick()

    const restoreConfirm = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-backup-restore-confirm"]',
    )
    expect(restoreConfirm).not.toBeNull()
    expect(restoreConfirm?.disabled).toBe(true)
    expect(restoreConfirm?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    restoreConfirm?.click()
    await settled()
    expect(dispatched).toHaveLength(0)

    const cancelButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-backup-restore-cancel"]',
    )
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    cancelButton?.click()
    await settled()
    expect(
      document.querySelector('[data-id="hilos-backup-restore-id"]'),
    ).toBeNull()
  })

  it.each([true, false])(
    'an admin sees the navigation panel only when offered=%s',
    async (offered) => {
      const { handshake } = bindSession()
      handshake({ id: 1, admin: true }, true)
      const { actions, dispatched } = makeActions()
      const { context } = seededContext([ARCHIVE_FIXTURE], actions, {
        backupReopen: { offered },
      })
      const pageRouter = router()
      const navigate = vi.spyOn(pageRouter, 'navigate')
      const wrapper = await mountPage(context, pageRouter)
      expect(
        wrapper.find('[data-id="hilos-backup-reopen-panel"]').exists(),
      ).toBe(offered)
      expect(wrapper.find('[data-id="hilos-backup-reopen"]').exists()).toBe(
        offered,
      )
      if (offered) {
        const panel = wrapper.get('[data-id="hilos-backup-reopen-panel"]')
        expect(panel.classes()).toContain('alert-warning')
        expect(panel.get('.fw-semibold').text()).toBe(
          'Verification window open',
        )
        expect(panel.get('.small').text()).toBe(
          'The verification window is open and the system remains closed to visitors. ' +
            'Check the system, then open it in Maintenance. Every connected page reloads when you do.',
        )
        const link = panel.get('a[data-id="hilos-backup-reopen"]')
        expect(link.attributes('href')).toBe('/hilos/maintenance')
        expect(link.text()).toBe('Open the system')
        expect(link.get('i').attributes('aria-hidden')).toBe('true')
        await link.trigger('click')
        expect(navigate).toHaveBeenCalledExactlyOnceWith('/hilos/maintenance')
      }
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(
        document.querySelector('[data-id="hilos-backup-reopen-confirm"]'),
      ).toBeNull()
      expect(dispatched).toHaveLength(0)
    },
  )

  it('withdraws the admin navigation panel when the live offer becomes false', async () => {
    const { handshake } = bindSession()
    handshake({ id: 1, admin: true }, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions, {
      backupReopen: { offered: true },
    })
    const wrapper = await mountPage(context)
    expect(wrapper.find('[data-id="hilos-backup-reopen-panel"]').exists()).toBe(
      true,
    )
    context.scopes.page()!.data.set('backupReopen', { offered: false })
    await settled()
    expect(wrapper.find('[data-id="hilos-backup-reopen-panel"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-backup-reopen"]').exists()).toBe(false)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-backup-reopen-confirm"]'),
    ).toBeNull()
    expect(dispatched).toHaveLength(0)
  })

  it('a viewer follows the verification link without opening a mutation dialog', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions, {
      backupReopen: { offered: true },
    })
    const pageRouter = router()
    const navigate = vi.spyOn(pageRouter, 'navigate')
    const wrapper = await mountPage(context, pageRouter)
    const link = wrapper.get('a[data-id="hilos-backup-reopen"]')

    expect(link.attributes('href')).toBe('/hilos/maintenance')
    expect(link.attributes('aria-disabled')).toBeUndefined()
    expect(link.attributes('aria-describedby')).toBeUndefined()
    await link.trigger('click')
    await settled()

    expect(navigate).toHaveBeenCalledExactlyOnceWith('/hilos/maintenance')
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-backup-reopen-confirm"]'),
    ).toBeNull()
    expect(dispatched).toHaveLength(0)
  })

  it('a viewer marks a row and finds the bulk delete standing in view mode', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions)
    await mountPage(context)

    const selectCheckbox = document.querySelector<HTMLInputElement>(
      `table [data-id="hilos-table-select-${ARCHIVE_ID}"]`,
    )
    expect(selectCheckbox).not.toBeNull()
    selectCheckbox?.click()
    await nextTick()
    expect(selectCheckbox?.checked).toBe(true)

    const bulkDelete = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-table-bulk-delete"]',
    )
    expect(bulkDelete).not.toBeNull()
    expect(bulkDelete?.disabled).toBe(true)
    expect(bulkDelete?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    bulkDelete?.click()
    await nextTick()
    expect(
      document.querySelector('[data-id="hilos-table-bulk-confirm"]'),
    ).toBeNull()
    expect(dispatched).toHaveLength(0)

    const clearButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-table-selection-clear"]',
    )
    expect(clearButton).not.toBeNull()
    expect(clearButton?.disabled).toBe(false)
    clearButton?.click()
    await nextTick()
    expect(selectCheckbox?.checked).toBe(false)
  })

  it('an admin on a node in the mode keeps every control live', async () => {
    const { handshake } = bindSession()
    handshake({ id: 1, admin: true }, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions, {
      backupRestore: { uiEnabled: true, targetEnv: 'dev' },
    })
    await mountPage(context)

    const keepSwitch = document.querySelector<HTMLInputElement>(
      `table [data-id="hilos-backup-keep-${ARCHIVE_ID}"]`,
    )
    expect(keepSwitch?.disabled).toBe(false)
    expect(keepSwitch?.getAttribute('aria-describedby')).toBeNull()

    const restoreButton = document.querySelector<HTMLButtonElement>(
      `table [data-id="hilos-backup-restore-${ARCHIVE_ID}"]`,
    )
    restoreButton?.click()
    await nextTick()

    const idInput = document.querySelector<HTMLInputElement>(
      '[data-id="hilos-backup-restore-id"]',
    )
    idInput!.value = ARCHIVE_ID
    idInput!.dispatchEvent(new Event('input'))
    await nextTick()

    const restoreConfirm = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-backup-restore-confirm"]',
    )
    expect(restoreConfirm?.disabled).toBe(false)

    const restoreCancel = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-backup-restore-cancel"]',
    )
    restoreCancel?.click()
    await settled()

    const deleteButton = document.querySelector<HTMLButtonElement>(
      `table [data-id="hilos-backup-delete-${ARCHIVE_ID}"]`,
    )
    deleteButton?.click()
    await nextTick()

    const deleteConfirm = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-backup-delete-confirm"]',
    )
    expect(deleteConfirm?.disabled).toBe(false)
    expect(deleteConfirm?.getAttribute('aria-describedby')).toBeNull()

    deleteConfirm?.click()
    await settled()

    expect(dispatched).toHaveLength(1)
    expect(dispatched[0]).toMatchObject({
      action: 'backup_delete',
      payload: { backupId: ARCHIVE_ID },
    })
  })
})

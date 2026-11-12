// The React peer of vue/src/admin/backup/HilosBackupPage.test.ts, the cases of the
// admin view mode (HIL-1260, HIL-1261, HIL-1264): the mark where a free text is
// hidden, and every control of the page a viewer finds standing — create, delete,
// keep, restore and the bulk delete — with navigation, Cancel and Clear alive.
import { act, cleanup, fireEvent, render, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  type TableViewportController,
  ActionError,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core/base'
import {
  createAppPageRouter,
  formatMoment,
  hilosBrowserTimeZone,
  hilosTimeZone,
  type HilosPageIdentity,
  type HilosTableProgressFrame,
} from '@hilos/core/base'
import {
  BACKUP_RESTORE_PROGRESS_SIGNAL,
  type HilosRestoreStatus,
} from '@hilos/core/admin/backup'
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosConnection,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core/base'
import type { HilosBackupsContext } from '@hilos/core/admin/backup'

import { HilosBackupPage } from '../src/admin/backup/HilosBackupPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { readerTimeZone } from './readerTimeZone.js'

const BACKUPS_TABLE = 'hilosBackups'
const ARCHIVE_ID = '2026-09-10_03-00-00'

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
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
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
  holderNodeName: string | null
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
    holderNode: null,
    holderNodeName: null,
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
  appliedFilter?: Readonly<Record<string, unknown>>
  backupRestore?: { uiEnabled: boolean; targetEnv?: string }
  backupReopen?: { offered: boolean }
  progress?: readonly HilosTableProgressFrame[]
}

interface SeededBackupsContext extends HilosBackupsContext {
  /** Deliver a live replacement for an archive row.
   * @param row The updated archive row.
   */
  updateBackupRow(row: BackupFixture): void
  /** Deliver the next table progress frame.
   * @param progress The server's running or ended work.
   */
  updateProgress(progress: HilosTableProgressFrame): void
  /** Deliver the initiator's restore status.
   * @param status The server's restore snapshot.
   */
  updateRestoreStatus(status: HilosRestoreStatus): void
}

/** A backups context whose window serves the given archives and whose actions are the given ones. */
function seededContext(
  initial: BackupFixture[] = [ARCHIVE_FIXTURE],
  actions: ActionLifecycle = makeActions().actions,
  options: SeededOptions = {},
): SeededBackupsContext {
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
  const progressListeners = new Set<(signal: { data: unknown }) => void>()
  const projectListeners = new Set<(signal: { data: unknown }) => void>()
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

  const connection = {
    registerTableWindow(
      _key: string,
      controller: TableViewportController<unknown>,
    ): void {
      controller.ingestSubscriptionWindow(
        rows.map(wireRow),
        rows.length,
        true,
        null,
        null,
        10,
        [{ field: 'createdAt', direction: 'desc' }],
        options.appliedFilter ?? {},
        options.progress ?? [],
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
      if (event === 'tableProgress') {
        progressListeners.add(listener)

        return () => progressListeners.delete(listener)
      }
      if (event === 'projectSignal') {
        projectListeners.add(listener)

        return () => projectListeners.delete(listener)
      }

      return () => {}
    },
  }

  return {
    connection: connection as unknown as HilosBackupsContext['connection'],
    scopes,
    actions,
    updateProgress(progress): void {
      act(() => {
        for (const listener of progressListeners) {
          listener({
            data: {
              ...progress,
              page: HilosPages.BACKUP,
              tableKey: BACKUPS_TABLE,
            },
          })
        }
      })
    },
    updateRestoreStatus(status): void {
      act(() => {
        for (const listener of projectListeners) {
          listener({
            type: BACKUP_RESTORE_PROGRESS_SIGNAL,
            data: status,
          } as ProjectSignal)
        }
      })
    },
    updateBackupRow(row): void {
      act(() => {
        const rowIndex = rows.findIndex((current) => current.id === row.id)
        if (rowIndex < 0) {
          throw new Error(`No backup row exists for ${row.id}`)
        }

        rows[rowIndex] = row
        for (const listener of deltaListeners) {
          listener({
            data: {
              page: HilosPages.BACKUP,
              tableKey: BACKUPS_TABLE,
              kind: 'row_updated',
              rowKey: row.id,
              row: wireRow(row),
            },
          })
        }
      })
    },
  }
}

function mountPage(
  context: HilosBackupsContext,
  pageRouter: HilosRouter = router(),
): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={pageRouter}>
      <HilosBackupPage context={context} />
    </HilosRouterContext.Provider>,
  ).container
}

/** Wait out the microtasks a settled action resolves through. */
async function settled(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

/** The element standing under the id in the document, the modals' portal included. */
function byId<T extends HTMLElement = HTMLElement>(id: string): T | null {
  return document.querySelector<T>(`[data-id="${id}"]`)
}

/** The element of a table row's control: the table is asked, not the card the row becomes. */
function inTable<T extends HTMLElement = HTMLElement>(id: string): T | null {
  return document.querySelector<T>(`table [data-id="${id}"]`)
}

/** The table projection of a control in one backup row. */
function inBackupRow<T extends HTMLElement = HTMLElement>(
  rowId: string,
  controlId: string,
): T | null {
  return document.querySelector<T>(
    `table [data-id="hilos-table-row-${rowId}"] [data-id="${controlId}"]`,
  )
}

/** The narrow-card projection of a control in one backup row. */
function inBackupCard<T extends HTMLElement = HTMLElement>(
  rowId: string,
  controlId: string,
): T | null {
  return document.querySelector<T>(
    `[data-id="hilos-table-card-${rowId}"] [data-id="${controlId}"]`,
  )
}

afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

describe('HilosBackupPage Maintenance navigation and run messages', () => {
  it('draws the catalog section head with one heading and no Overview-only tab row', () => {
    const identity: HilosPageIdentity = {
      label: 'Backups',
      lead: 'Stored backups and restore history.',
      breadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.BACKUP, label: 'Backups' },
      ],
      children: [],
      pageHeadOwner: {
        page: HilosPages.BACKUP,
        label: 'Backups',
        lead: 'Stored backups and restore history.',
        icon: 'bi-safe2',
      },
      pageTabs: [{ page: HilosPages.BACKUP, label: 'Overview' }],
    }
    const container = mountPage(seededContext(), {
      ...router(),
      pageIdentity: createSignal(identity),
      resolvePath: createAppPageRouter({}).path,
    })

    expect(container.querySelectorAll('h1')).toHaveLength(1)
    expect(container.querySelector('h1')?.textContent).toBe('Backups')
    expect(container.textContent).toContain(
      'Stored backups and restore history.',
    )
    const breadcrumb = container.querySelector('nav[aria-label="Breadcrumb"]')
    expect(breadcrumb?.querySelector('a')?.getAttribute('href')).toBe('/hilos')
    expect(
      within(breadcrumb as HTMLElement)
        .getByText('Backups')
        .getAttribute('aria-current'),
    ).toBe('page')
    expect(container.querySelector('[data-id="hilos-page-tabs"]')).toBeNull()
  })

  it('holds the section head skeleton until the page identity arrives', () => {
    const container = mountPage(seededContext())

    expect(byId('hilos-page-head-skeleton')).not.toBeNull()
    expect(container.querySelector('h1')).toBeNull()
    expect(byId('hilos-breadcrumb')).toBeNull()
    expect(byId('hilos-page-tabs')).toBeNull()
  })

  it.each([
    { databaseTouched: false, rehydrateComplete: false },
    { databaseTouched: true, rehydrateComplete: false },
    { databaseTouched: false, rehydrateComplete: true },
    { databaseTouched: true, rehydrateComplete: true },
  ])(
    'reports databaseTouched=$databaseTouched and rehydrateComplete=$rehydrateComplete independently',
    (state) => {
      const context = seededContext()
      mountPage(context)
      context.updateRestoreStatus({
        running: false,
        backupId: ARCHIVE_ID,
        scope: 'full',
        phase: 'failed',
        phaseStartedAt: null,
        startedAt: null,
        finishedAt: null,
        outcome: 'error',
        failureReason: 'The archive could not be imported.',
        estimatedSeconds: null,
        rehydrateProblems: [],
        ...state,
      })
      const panel = byId('hilos-backup-restore-phase')
      expect(panel?.getAttribute('role')).toBe('status')
      expect(panel?.textContent).toContain('The archive could not be imported.')
      expect(
        panel?.textContent?.includes(
          'The database was already being replaced when this failed.',
        ),
      ).toBe(state.databaseTouched)
      expect(panel?.textContent?.includes('The system stays closed:')).toBe(
        !state.rehydrateComplete,
      )
      expect(panel?.textContent?.includes('Open it in Maintenance')).toBe(
        !state.rehydrateComplete,
      )
      expect(panel?.textContent?.includes('/hilos/maintenance')).toBe(
        !state.rehydrateComplete,
      )
      expect(
        panel?.textContent?.includes('php cli.php protected-mode:open'),
      ).toBe(!state.rehydrateComplete)
    },
  )

  it('names the backup in the initial progress caption and status, then follows live updates and the end', () => {
    const initial: HilosTableProgressFrame = {
      scope: 'table',
      progressKey: 'backup-create',
      current: 25,
      total: 100,
      detail: {
        backupId: ARCHIVE_ID,
        env: 'dev',
        scope: 'full',
        phase: 'dumping',
        remainingSeconds: 12,
      },
    }
    const context = seededContext([], undefined, { progress: [initial] })
    mountPage(context)
    const title = `Backup ${ARCHIVE_ID} · dev · full`
    expect(
      byId('hilos-table-progress')?.querySelector('strong')?.textContent,
    ).toBe(title)
    expect(byId('hilos-table-progress')?.textContent).toBe(
      `${title} — dumping · 25% · ~12s left`,
    )
    expect(byId('hilos-table-live-status')?.getAttribute('role')).toBe('status')
    expect(byId('hilos-table-live-status')?.textContent).toBe(
      `${title} — dumping · 25% · ~12s left`,
    )

    context.updateProgress({
      ...initial,
      current: 75,
      detail: { ...initial.detail, phase: 'archiving', remainingSeconds: 4 },
    })
    expect(byId('hilos-table-progress')?.textContent).toBe(
      `${title} — archiving · 75% · ~4s left`,
    )
    expect(byId('hilos-table-live-status')?.textContent).toBe(
      `${title} — archiving · 75% · ~4s left`,
    )

    context.updateProgress({ ...initial, ended: true })
    expect(byId('hilos-table-progress')).toBeNull()
    expect(byId('hilos-table-live-status')?.textContent).toBe('')
  })

  it('keeps old progress frames readable and names missing environment and scope without an estimate', () => {
    const context = seededContext([], undefined, {
      progress: [
        {
          scope: 'table',
          progressKey: 'legacy',
          current: 0,
          detail: { phase: 'dumping', remainingSeconds: 12 },
        },
      ],
    })
    mountPage(context)
    expect(byId('hilos-table-progress')?.querySelector('strong')).toBeNull()
    expect(byId('hilos-table-progress')?.textContent).toBe('dumping')
    expect(byId('hilos-table-live-status')?.textContent).toBe('dumping')

    context.updateProgress({
      scope: 'table',
      progressKey: 'legacy',
      current: 0,
      detail: {
        backupId: ARCHIVE_ID,
        phase: 'archiving',
        remainingSeconds: 10,
      },
    })
    expect(
      byId('hilos-table-progress')?.querySelector('strong')?.textContent,
    ).toBe(`Backup ${ARCHIVE_ID} · — · —`)
    expect(byId('hilos-table-progress')?.textContent).toBe(
      `Backup ${ARCHIVE_ID} · — · — — archiving`,
    )
    expect(byId('hilos-table-live-status')?.textContent).toBe(
      `Backup ${ARCHIVE_ID} · — · — — archiving`,
    )
  })
})

describe('HilosBackupPage reader-zone moments', () => {
  it('reformats held backup moments without replacing its table, filters, or open outcome', () => {
    const reader = readerTimeZone()
    const browserZone = hilosBrowserTimeZone.get() ?? 'UTC'
    const firstZone =
      browserZone === 'America/Los_Angeles' || browserZone === 'Europe/Kyiv'
        ? 'Pacific/Honolulu'
        : 'America/Los_Angeles'
    const nextZone =
      browserZone === 'Europe/Kyiv' || browserZone === 'Pacific/Honolulu'
        ? 'Asia/Tokyo'
        : 'Europe/Kyiv'
    const createdAt = '2026-01-01T00:30:05.123456Z'
    const verifiedAt = '2026-01-02T00:30:06.123456Z'
    const shippedAt = '2026-01-03T00:30:07.123456Z'
    const restoreFinishedAt = '2026-01-04T00:30:08.123456Z'

    try {
      const browserCreatedAt = formatMoment(createdAt, { kind: 'dateTime' })
      act(() => reader.choose(firstZone))
      const context = seededContext([
        {
          id: ARCHIVE_ID,
          backup: {
            ...ARCHIVE_FIXTURE.backup,
            createdAt,
            verifiedAt,
            shipState: 'shipped',
            shippedAt,
            restoreOutcome: 'success',
            restoreFinishedAt,
          },
        },
      ])
      const registrations = vi.spyOn(context.connection, 'registerTableWindow')
      const viewport = vi.spyOn(context.connection, 'sendTableViewport')
      const container = mountPage(context)
      viewport.mockReturnValue(true)
      expect(registrations).toHaveBeenCalledTimes(1)

      const search = byId<HTMLInputElement>('hilos-table-search')
      fireEvent.change(search as HTMLInputElement, {
        target: { value: 'archive' },
      })
      fireEvent.click(
        byId('hilos-table-filter-scope')?.querySelector(
          'button',
        ) as HTMLElement,
      )
      fireEvent.click(byId('hilos-dropdown-option-0') as HTMLElement)
      fireEvent.click(byId('hilos-table-filter-from') as HTMLElement)
      fireEvent.change(
        byId<HTMLInputElement>('hilos-table-filter-from-from') as HTMLElement,
        { target: { value: '2025-12-31' } },
      )
      fireEvent.change(
        byId<HTMLInputElement>('hilos-table-filter-from-to') as HTMLElement,
        { target: { value: '2026-01-04' } },
      )

      const table = container.querySelector('table')
      const row = inTable(`hilos-table-row-${ARCHIVE_ID}`)
      fireEvent.click(
        inTable(`hilos-backup-restore-outcome-${ARCHIVE_ID}`) as HTMLElement,
      )
      const outcome = byId('hilos-backup-restore-outcome-finished')
      const dialog = outcome?.closest('[role="dialog"]')
      const before = {
        createdAt: inTable('hilos-backup-created-at')?.textContent,
        checksum: inTable('hilos-backup-checksum')?.textContent,
        shipping: inTable('hilos-backup-shipping')?.textContent,
      }
      const firstOutcome = `Finished ${formatMoment(restoreFinishedAt, { kind: 'dateTime' }) ?? '—'} · success`

      expect(firstZone).not.toBe(browserZone)
      expect(hilosTimeZone.get()).toBe(firstZone)
      expect(before.createdAt).toBe(
        formatMoment(createdAt, { kind: 'dateTime' }),
      )
      expect(before.createdAt).not.toBe(browserCreatedAt)
      expect(before.checksum).toBe(
        `✓ ${formatMoment(verifiedAt, { kind: 'date' }) ?? '—'}`,
      )
      expect(before.shipping).toBe(
        `✓ ${formatMoment(shippedAt, { kind: 'date' }) ?? '—'}`,
      )
      expect(outcome?.textContent?.trim()).toBe(firstOutcome)

      act(() => reader.choose(nextZone))

      const after = {
        createdAt: inTable('hilos-backup-created-at')?.textContent,
        checksum: inTable('hilos-backup-checksum')?.textContent,
        shipping: inTable('hilos-backup-shipping')?.textContent,
      }
      const latestRequest = viewport.mock.calls.at(-1)

      expect(nextZone).not.toBe(browserZone)
      expect(hilosTimeZone.get()).toBe(nextZone)
      expect(after.createdAt).toBe(
        formatMoment(createdAt, { kind: 'dateTime' }),
      )
      expect(after.createdAt).not.toBe(before.createdAt)
      expect(after.checksum).toBe(
        `✓ ${formatMoment(verifiedAt, { kind: 'date' }) ?? '—'}`,
      )
      expect(after.checksum).not.toBe(before.checksum)
      expect(after.shipping).toBe(
        `✓ ${formatMoment(shippedAt, { kind: 'date' }) ?? '—'}`,
      )
      expect(after.shipping).not.toBe(before.shipping)
      expect(outcome?.textContent?.trim()).toBe(
        `Finished ${formatMoment(restoreFinishedAt, { kind: 'dateTime' }) ?? '—'} · success`,
      )
      expect(outcome).toBe(byId('hilos-backup-restore-outcome-finished'))
      expect(outcome?.closest('[role="dialog"]')).toBe(dialog)
      expect(container.querySelector('table')).toBe(table)
      expect(inTable(`hilos-table-row-${ARCHIVE_ID}`)).toBe(row)
      expect(search?.value).toBe('archive')
      expect(byId('hilos-table-filter-scope')?.textContent).toContain(
        'Full (schema + all data)',
      )
      expect(byId('hilos-table-filter-from')?.textContent).toContain(
        '2025-12-31 – 2026-01-04',
      )
      expect(latestRequest?.[2]?.filter).toMatchObject({
        scope: 'full',
        from: '2025-12-31',
        to: '2026-01-04',
        timeZone: nextZone,
      })
      expect(registrations).toHaveBeenCalledTimes(1)
    } finally {
      cleanup()
      reader.release()
    }
  })

  it('keeps an ISO UTC epoch-zero backup moment valid', () => {
    const reader = readerTimeZone()
    const epochZero = '1970-01-01T00:00:00.000000Z'

    try {
      act(() => reader.choose('Pacific/Kiritimati'))
      const context = seededContext([
        {
          id: ARCHIVE_ID,
          backup: {
            ...ARCHIVE_FIXTURE.backup,
            createdAt: epochZero,
            verifiedAt: epochZero,
            shipState: 'shipped',
            shippedAt: epochZero,
            restoreOutcome: 'success',
            restoreFinishedAt: epochZero,
          },
        },
      ])
      mountPage(context)

      expect(inTable('hilos-backup-created-at')?.textContent).toBe(
        formatMoment(epochZero, { kind: 'dateTime' }),
      )
      expect(inTable('hilos-backup-created-at')?.textContent).not.toBe('—')
      expect(inTable('hilos-backup-checksum')?.textContent).toBe(
        `✓ ${formatMoment(epochZero, { kind: 'date' })}`,
      )
      expect(inTable('hilos-backup-shipping')?.textContent).toBe(
        `✓ ${formatMoment(epochZero, { kind: 'date' })}`,
      )
      fireEvent.click(
        inTable(`hilos-backup-restore-outcome-${ARCHIVE_ID}`) as HTMLElement,
      )
      expect(
        byId('hilos-backup-restore-outcome-finished')?.textContent?.trim(),
      ).toBe(
        `Finished ${formatMoment(epochZero, { kind: 'dateTime' })} · success`,
      )
    } finally {
      cleanup()
      reader.release()
    }
  })

  it.each([null, 'malformed', '2026-02-30T00:00:00.000000Z'])(
    'keeps the absent marker for an unrepresentable backup moment: %s',
    (moment) => {
      const reader = readerTimeZone()

      try {
        const context = seededContext([
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
        mountPage(context)

        expect(inTable('hilos-backup-created-at')?.textContent).toBe('—')
        expect(inTable('hilos-backup-checksum')?.textContent).toBe('✓')
        expect(inTable('hilos-backup-shipping')?.textContent).toBe('✓')
        fireEvent.click(
          inTable(`hilos-backup-restore-outcome-${ARCHIVE_ID}`) as HTMLElement,
        )
        expect(byId('hilos-backup-restore-outcome-finished')?.textContent).toBe(
          'Finished — · success',
        )
      } finally {
        cleanup()
        reader.release()
      }
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
    return byId('modal')
  }

  it('keeps the button of a hidden reason and opens the mark in place of the text', () => {
    const context = seededContext([
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
    mountPage(context)

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

    fireEvent.click(inTable(`hilos-backup-details-${FAILED_ID}`) as Element)
    expect(
      modal()?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
    expect(
      modal()?.querySelector('[data-id="hilos-backup-details-text"]'),
    ).toBeNull()
    fireEvent.click(byId('hilos-backup-details-close') as Element)

    fireEvent.click(
      inTable(`hilos-backup-ship-why-${SHIP_FAILED_ID}`) as Element,
    )
    expect(
      modal()?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
    expect(
      modal()?.querySelector('[data-id="hilos-backup-ship-error-text"]'),
    ).toBeNull()
  })
})

describe('HilosBackupPage why a restore is blocked', () => {
  const RESTORE_GATE = {
    backupRestore: { uiEnabled: true, targetEnv: 'dev' },
  }
  const OUT_OF_REACH_REASON =
    'Stored on node node-2 - the backup agent runs elsewhere, so this archive cannot be reached from here'

  it('names the holder badge and refusal, falls back to id, and keeps restore unavailable', () => {
    const { actions, dispatched } = makeActions()
    const archive = {
      ...ARCHIVE_FIXTURE,
      backup: {
        ...ARCHIVE_FIXTURE.backup,
        holderNode: 'node-2',
        holderNodeName: 'North',
      },
    }
    const context = seededContext([archive], actions, RESTORE_GATE)
    mountPage(context)

    const holder = inTable(`hilos-backup-holder-${ARCHIVE_ID}`)!
    expect(holder.textContent).toBe('North (node-2)')
    expect(holder.title).toBe(
      'Stored on node North (node-2) - the backup agent runs elsewhere, so this archive cannot be reached from here',
    )
    const restore = inTable<HTMLButtonElement>(
      `hilos-backup-restore-${ARCHIVE_ID}`,
    )!
    expect(restore.disabled).toBe(true)
    fireEvent.click(inBackupRow(ARCHIVE_ID, 'hilos-action-reason')!)
    expect(byId('hilos-action-reason-body')?.textContent).toBe(holder.title)
    fireEvent.keyDown(byId('modal')!, { key: 'Escape' })

    context.updateBackupRow({
      ...archive,
      backup: { ...archive.backup, holderNodeName: null },
    })
    expect(inTable(`hilos-backup-holder-${ARCHIVE_ID}`)?.textContent).toBe(
      'node-2',
    )
    expect(inBackupRow(ARCHIVE_ID, 'hilos-action-reason')?.title).toBe(
      OUT_OF_REACH_REASON,
    )
    expect(inTable(`hilos-backup-restore-${ARCHIVE_ID}`)).toBe(restore)
    expect(restore.disabled).toBe(true)
    expect(dispatched).toHaveLength(0)

    context.updateBackupRow({
      ...archive,
      backup: { ...archive.backup, holderNode: null, holderNodeName: null },
    })
    expect(inTable(`hilos-backup-holder-${ARCHIVE_ID}`)).toBeNull()
  })

  it.each([
    [
      'an archive stored on another node',
      { holderNode: 'node-2' },
      OUT_OF_REACH_REASON,
    ],
    [
      'a checksum mismatch',
      { checksumState: 'mismatch' },
      'This archive does not match its recorded checksum',
    ],
  ] as const)(
    'explains %s from the table and card without a restore request',
    (_scenario, backupFields, reason) => {
      const { actions, dispatched } = makeActions()
      const context = seededContext(
        [
          {
            ...ARCHIVE_FIXTURE,
            backup: { ...ARCHIVE_FIXTURE.backup, ...backupFields },
          },
        ],
        actions,
        RESTORE_GATE,
      )
      mountPage(context)

      const opener = inBackupRow<HTMLButtonElement>(
        ARCHIVE_ID,
        'hilos-action-reason',
      )
      expect(opener?.type).toBe('button')
      expect(opener?.disabled).toBe(false)
      expect(opener?.getAttribute('aria-label')).toBe(
        'Why this backup cannot be restored',
      )
      expect(opener?.title).toBe(reason)
      expect(
        inBackupCard<HTMLButtonElement>(
          ARCHIVE_ID,
          'hilos-action-reason',
        )?.getAttribute('aria-label'),
      ).toBe('Why this backup cannot be restored')
      expect(
        inTable<HTMLButtonElement>(`hilos-backup-restore-${ARCHIVE_ID}`)
          ?.disabled,
      ).toBe(true)

      opener?.focus()
      fireEvent.click(opener as HTMLButtonElement)

      const dialog = byId('modal')
      expect(dialog?.getAttribute('role')).toBe('dialog')
      expect(dialog?.getAttribute('aria-modal')).toBe('true')
      expect(dialog?.getAttribute('aria-label')).toBe(
        `Cannot restore · ${ARCHIVE_ID}`,
      )
      expect(document.activeElement).toBe(dialog)
      expect(byId('hilos-action-reason-body')?.textContent?.trim()).toBe(reason)
      expect(byId('hilos-backup-restore-id')).toBeNull()
      expect(byId('hilos-backup-restore-confirm')).toBeNull()
      expect(dispatched).toHaveLength(0)

      fireEvent.keyDown(dialog as Element, { key: 'Escape' })
      expect(byId('modal')).toBeNull()
      expect(document.activeElement).toBe(opener)
    },
  )

  it('shows the hidden reason through the mark without leaking it into a title or the DOM', () => {
    const { actions, dispatched } = makeActions()
    const context = seededContext(
      [
        {
          ...ARCHIVE_FIXTURE,
          backup: {
            ...ARCHIVE_FIXTURE.backup,
            restoreMigrationDecision: 'refuse',
            restoreMigrationNotice: { _hidden: true },
          },
        },
      ],
      actions,
      RESTORE_GATE,
    )
    mountPage(context)

    const opener = inBackupRow<HTMLButtonElement>(
      ARCHIVE_ID,
      'hilos-action-reason',
    )
    expect(opener?.hasAttribute('title')).toBe(false)
    expect(opener?.getAttribute('aria-label')).toBe(
      'Why this backup cannot be restored',
    )
    expect(
      inTable<HTMLButtonElement>(`hilos-backup-restore-${ARCHIVE_ID}`)
        ?.disabled,
    ).toBe(true)

    fireEvent.click(opener as HTMLButtonElement)

    const dialog = byId('modal')
    const body = byId('hilos-action-reason-body')
    expect(dialog?.getAttribute('aria-label')).toBe(
      `Cannot restore · ${ARCHIVE_ID}`,
    )
    expect(
      body?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
    expect(body?.textContent?.trim()).toBe('Hidden')
    expect(document.body.innerHTML).not.toContain('_hidden')
    expect(document.body.innerHTML).not.toContain('[object Object]')
    expect(dispatched).toHaveLength(0)
  })

  it('gives a restorable archive a live restore action and no reason opener', () => {
    const context = seededContext([ARCHIVE_FIXTURE], undefined, RESTORE_GATE)
    mountPage(context)

    expect(
      inTable<HTMLButtonElement>(`hilos-backup-restore-${ARCHIVE_ID}`)
        ?.disabled,
    ).toBe(false)
    expect(inBackupRow(ARCHIVE_ID, 'hilos-action-reason')).toBeNull()
    expect(inBackupCard(ARCHIVE_ID, 'hilos-action-reason')).toBeNull()
  })

  it('follows live restore eligibility and keeps the opened explanation snapshot', () => {
    const { actions, dispatched } = makeActions()
    const checksumBlocked = {
      ...ARCHIVE_FIXTURE,
      backup: { ...ARCHIVE_FIXTURE.backup, checksumState: 'mismatch' },
    }
    const outOfReach = {
      ...ARCHIVE_FIXTURE,
      backup: {
        ...ARCHIVE_FIXTURE.backup,
        holderNode: 'node-2',
        checksumState: 'verified',
      },
    }
    const context = seededContext([checksumBlocked], actions, RESTORE_GATE)
    mountPage(context)

    const opener = inBackupRow<HTMLButtonElement>(
      ARCHIVE_ID,
      'hilos-action-reason',
    )
    expect(opener?.title).toBe(
      'This archive does not match its recorded checksum',
    )
    fireEvent.click(opener as HTMLButtonElement)
    const dialog = byId('modal')
    expect(byId('hilos-action-reason-body')?.textContent?.trim()).toBe(
      'This archive does not match its recorded checksum',
    )

    context.updateBackupRow(outOfReach)
    expect(
      inBackupRow<HTMLButtonElement>(ARCHIVE_ID, 'hilos-action-reason')?.title,
    ).toBe(OUT_OF_REACH_REASON)
    expect(byId('hilos-action-reason-body')?.textContent?.trim()).toBe(
      'This archive does not match its recorded checksum',
    )

    context.updateBackupRow(ARCHIVE_FIXTURE)
    expect(inBackupRow(ARCHIVE_ID, 'hilos-action-reason')).toBeNull()
    expect(
      inTable<HTMLButtonElement>(`hilos-backup-restore-${ARCHIVE_ID}`)
        ?.disabled,
    ).toBe(false)
    expect(byId('modal')).toBe(dialog)
    expect(byId('hilos-action-reason-body')?.textContent?.trim()).toBe(
      'This archive does not match its recorded checksum',
    )
    fireEvent.click(byId('hilos-action-reason-close') as Element)

    context.updateBackupRow(outOfReach)
    const freshOpener = inBackupRow<HTMLButtonElement>(
      ARCHIVE_ID,
      'hilos-action-reason',
    )
    fireEvent.click(freshOpener as HTMLButtonElement)
    expect(byId('hilos-action-reason-body')?.textContent?.trim()).toBe(
      OUT_OF_REACH_REASON,
    )
    expect(dispatched).toHaveLength(0)
  })
})

describe('HilosBackupPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  /**
   * Bind the session scope and the admin access the way bootHilos does, over the
   * context's page scope and the handshakes this harness emits.
   *
   * @param scopes The context's scopes, the ones the page reads its access from.
   */
  function bindSession(scopes: ScopeManager) {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    bindSessionScope(connection, scopes)
    releases.push(bindAdminAccess(scopes))

    return {
      /**
       * One handshake: who is behind the session, if anybody, and the node's
       * admin view mode, both as the backend stamps them (HIL-1253).
       *
       * @param user The person behind the session, or null for a guest.
       * @param viewMode The node's admin view mode.
       */
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

  it('keeps a typed restore confirmation while the backup reader filter syncs', () => {
    const reader = readerTimeZone()
    const browserZone = hilosBrowserTimeZone.get() ?? 'UTC'
    const firstZone =
      browserZone === 'America/Los_Angeles' || browserZone === 'Europe/Kyiv'
        ? 'Pacific/Honolulu'
        : 'America/Los_Angeles'
    const nextZone =
      browserZone === 'Europe/Kyiv' || browserZone === 'Pacific/Honolulu'
        ? 'Asia/Tokyo'
        : 'Europe/Kyiv'

    try {
      act(() => reader.choose(firstZone))
      const { actions, dispatched } = makeActions()
      const context = seededContext([ARCHIVE_FIXTURE], actions, {
        backupRestore: { uiEnabled: true, targetEnv: 'dev' },
        appliedFilter: { timeZone: firstZone },
      })
      bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
      const registrations = vi.spyOn(context.connection, 'registerTableWindow')
      const viewport = vi
        .spyOn(context.connection, 'sendTableViewport')
        .mockReturnValue(true)
      const container = mountPage(context)
      const table = container.querySelector('table')
      const row = inTable(`hilos-table-row-${ARCHIVE_ID}`)

      fireEvent.click(
        inTable(`hilos-backup-restore-${ARCHIVE_ID}`) as HTMLElement,
      )
      const input = byId<HTMLInputElement>('hilos-backup-restore-id')
      fireEvent.change(input as HTMLInputElement, {
        target: { value: ARCHIVE_ID },
      })
      const dialog = input?.closest('[role="dialog"]')
      const confirm = byId<HTMLButtonElement>('hilos-backup-restore-confirm')

      expect(confirm?.disabled).toBe(false)
      act(() => reader.choose(nextZone))

      expect(hilosTimeZone.get()).toBe(nextZone)
      expect(input).toBe(byId('hilos-backup-restore-id'))
      expect(input?.value).toBe(ARCHIVE_ID)
      expect(confirm).toBe(byId('hilos-backup-restore-confirm'))
      expect(confirm?.disabled).toBe(false)
      expect(input?.closest('[role="dialog"]')).toBe(dialog)
      expect(container.querySelector('table')).toBe(table)
      expect(inTable(`hilos-table-row-${ARCHIVE_ID}`)).toBe(row)
      expect(registrations).toHaveBeenCalledTimes(1)
      expect(viewport.mock.calls.at(-1)?.[2]?.filter).toMatchObject({
        timeZone: nextZone,
      })
      expect(dispatched).toHaveLength(0)
    } finally {
      cleanup()
      reader.release()
    }
  })

  it('a viewer opens the create dialog, may pick a scope and has nothing to start it with', async () => {
    const { actions, dispatched } = makeActions()
    const context = seededContext([ARCHIVE_FIXTURE], actions)
    bindSession(context.scopes).handshake(null, true)
    const container = mountPage(context)

    const mainActionButton = container.querySelector<HTMLButtonElement>(
      '[data-id="hilos-table-main-action"]',
    )
    expect(mainActionButton).not.toBeNull()
    expect(mainActionButton?.disabled).toBe(false)
    fireEvent.click(mainActionButton as Element)

    const scopeSelect = byId<HTMLSelectElement>('hilos-backup-create-scope')
    expect(scopeSelect).not.toBeNull()
    expect(scopeSelect?.disabled).toBe(false)
    fireEvent.change(scopeSelect as Element, {
      target: { value: 'schema-only' },
    })
    expect(scopeSelect?.value).toBe('schema-only')

    const createConfirm = byId<HTMLButtonElement>('hilos-backup-create-confirm')
    expect(createConfirm).not.toBeNull()
    expect(createConfirm?.disabled).toBe(true)
    expect(createConfirm?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(createConfirm as Element)
    await settled()
    expect(dispatched).toHaveLength(0)

    const cancelButton = byId<HTMLButtonElement>('hilos-backup-create-cancel')
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    fireEvent.click(cancelButton as Element)
    expect(byId('hilos-backup-create-scope')).toBeNull()
  })

  it('a viewer opens the delete dialog over a row and has nothing to delete with', async () => {
    const { actions, dispatched } = makeActions()
    const context = seededContext([ARCHIVE_FIXTURE], actions)
    bindSession(context.scopes).handshake(null, true)
    mountPage(context)

    const trashButton = inTable<HTMLButtonElement>(
      `hilos-backup-delete-${ARCHIVE_ID}`,
    )
    expect(trashButton).not.toBeNull()
    expect(trashButton?.disabled).toBe(false)
    fireEvent.click(trashButton as Element)

    const deleteConfirm = byId<HTMLButtonElement>('hilos-backup-delete-confirm')
    expect(deleteConfirm).not.toBeNull()
    expect(deleteConfirm?.disabled).toBe(true)
    expect(deleteConfirm?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(deleteConfirm as Element)
    await settled()
    expect(dispatched).toHaveLength(0)

    const cancelButton = byId<HTMLButtonElement>('hilos-backup-delete-cancel')
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    fireEvent.click(cancelButton as Element)
    expect(byId('hilos-backup-delete-confirm')).toBeNull()
  })

  it('a viewer finds the keep switch standing where the server put it', async () => {
    const { actions, dispatched } = makeActions()
    const context = seededContext([ARCHIVE_FIXTURE], actions)
    bindSession(context.scopes).handshake(null, true)
    mountPage(context)

    const keepSwitch = inTable<HTMLInputElement>(
      `hilos-backup-keep-${ARCHIVE_ID}`,
    )
    expect(keepSwitch).not.toBeNull()
    expect(keepSwitch?.disabled).toBe(true)
    expect(keepSwitch?.checked).toBe(true)
    expect(keepSwitch?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(keepSwitch as Element)
    await settled()
    expect(dispatched).toHaveLength(0)
  })

  it('a viewer opens the restore dialog, types the archive id and has nothing to restore with', async () => {
    const { actions, dispatched } = makeActions()
    const context = seededContext([ARCHIVE_FIXTURE], actions, {
      backupRestore: { uiEnabled: true, targetEnv: 'dev' },
    })
    bindSession(context.scopes).handshake(null, true)
    mountPage(context)

    const restoreButton = inTable<HTMLButtonElement>(
      `hilos-backup-restore-${ARCHIVE_ID}`,
    )
    expect(restoreButton).not.toBeNull()
    expect(restoreButton?.disabled).toBe(false)
    fireEvent.click(restoreButton as Element)

    const idInput = byId<HTMLInputElement>('hilos-backup-restore-id')
    expect(idInput).not.toBeNull()
    expect(idInput?.disabled).toBe(false)
    fireEvent.change(idInput as Element, { target: { value: ARCHIVE_ID } })

    const restoreConfirm = byId<HTMLButtonElement>(
      'hilos-backup-restore-confirm',
    )
    expect(restoreConfirm).not.toBeNull()
    expect(restoreConfirm?.disabled).toBe(true)
    expect(restoreConfirm?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(restoreConfirm as Element)
    await settled()
    expect(dispatched).toHaveLength(0)

    const cancelButton = byId<HTMLButtonElement>('hilos-backup-restore-cancel')
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    fireEvent.click(cancelButton as Element)
    expect(byId('hilos-backup-restore-id')).toBeNull()
  })

  it.each([undefined, { offered: false }])(
    'hides verification navigation without a personal offer: %s',
    (backupReopen) => {
      const { actions, dispatched } = makeActions()
      const context = seededContext([ARCHIVE_FIXTURE], actions, {
        backupReopen,
      })
      bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
      mountPage(context)

      expect(byId('hilos-backup-reopen-panel')).toBeNull()
      expect(byId('hilos-backup-reopen')).toBeNull()
      expect(dispatched).toHaveLength(0)
    },
  )

  it('withdraws the verification navigation when the personal offer is removed live', () => {
    const { actions, dispatched } = makeActions()
    const context = seededContext([ARCHIVE_FIXTURE], actions, {
      backupReopen: { offered: true },
    })
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    mountPage(context)

    const panel = byId('hilos-backup-reopen-panel')
    expect(panel?.getAttribute('role')).toBe('status')
    expect(panel?.textContent).toContain('Verification window open')
    expect(panel?.textContent).toContain(
      'The verification window is open and the system remains closed to visitors. Check the system, then open it in Maintenance. Every connected page reloads when you do.',
    )
    expect(byId('hilos-backup-reopen')?.textContent).toBe('Open the system')
    act(() =>
      context.scopes.page()!.data.set('backupReopen', { offered: false }),
    )
    expect(byId('hilos-backup-reopen-panel')).toBeNull()
    expect(byId('hilos-backup-reopen')).toBeNull()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(dispatched).toHaveLength(0)
  })

  it('a viewer follows the verification and Circle links without opening a mutation dialog', async () => {
    const { actions, dispatched } = makeActions()
    const context = seededContext([ARCHIVE_FIXTURE], actions, {
      backupReopen: { offered: true },
    })
    bindSession(context.scopes).handshake(null, true)
    const pageRouter = router()
    const navigate = vi.spyOn(pageRouter, 'navigate')
    mountPage(context, pageRouter)

    const link = byId<HTMLAnchorElement>('hilos-backup-reopen')
    expect(link?.tagName).toBe('A')
    expect(link?.getAttribute('href')).toBe('/hilos/maintenance')
    expect(link?.hasAttribute('aria-disabled')).toBe(false)
    expect(link?.hasAttribute('aria-describedby')).toBe(false)
    expect(link?.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    fireEvent.click(link as Element)
    await settled()

    expect(navigate).toHaveBeenCalledExactlyOnceWith('/hilos/maintenance')
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(byId('hilos-backup-reopen-confirm')).toBeNull()
    expect(dispatched).toHaveLength(0)

    const circle = byId<HTMLAnchorElement>('hilos-backup-circle-pointer-open')
    expect(circle?.tagName).toBe('A')
    expect(circle?.getAttribute('href')).toBe('/hilos/maintenance/circle')
    expect(circle?.textContent).toBe('Open Maintenance')
    expect(byId('hilos-backup-circle-pointer')?.textContent).toContain(
      'The verifier circle is kept at /hilos/maintenance/circle.',
    )
    expect(circle?.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    fireEvent.click(circle as Element)
    expect(navigate).toHaveBeenLastCalledWith('/hilos/maintenance/circle')
    expect(navigate).toHaveBeenCalledTimes(2)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(dispatched).toHaveLength(0)
  })

  it.each([
    { ctrlKey: true },
    { metaKey: true },
    { shiftKey: true },
    { altKey: true },
    { button: 1 },
  ])('leaves modified verification clicks to the browser: %s', (modifiers) => {
    const { actions, dispatched } = makeActions()
    const context = seededContext([ARCHIVE_FIXTURE], actions, {
      backupReopen: { offered: true },
    })
    bindSession(context.scopes).handshake(null, true)
    const pageRouter = router()
    const navigate = vi.spyOn(pageRouter, 'navigate')
    mountPage(context, pageRouter)
    let intercepted: boolean | undefined
    // Observe React's decision, then stop jsdom from attempting native navigation.
    document.addEventListener(
      'click',
      (event) => {
        intercepted = event.defaultPrevented
        event.preventDefault()
      },
      { once: true },
    )
    fireEvent.click(byId('hilos-backup-reopen') as Element, modifiers)

    expect(intercepted).toBe(false)
    expect(navigate).not.toHaveBeenCalled()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(dispatched).toHaveLength(0)
  })

  it('a viewer marks a row and finds the bulk delete standing in view mode', async () => {
    const { actions, dispatched } = makeActions()
    const context = seededContext([ARCHIVE_FIXTURE], actions)
    bindSession(context.scopes).handshake(null, true)
    mountPage(context)

    const selectCheckbox = inTable<HTMLInputElement>(
      `hilos-table-select-${ARCHIVE_ID}`,
    )
    expect(selectCheckbox).not.toBeNull()
    fireEvent.click(selectCheckbox as Element)
    expect(selectCheckbox?.checked).toBe(true)

    const bulkDelete = byId<HTMLButtonElement>('hilos-table-bulk-delete')
    expect(bulkDelete).not.toBeNull()
    expect(bulkDelete?.disabled).toBe(true)
    expect(bulkDelete?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(bulkDelete as Element)
    expect(byId('hilos-table-bulk-confirm')).toBeNull()
    expect(dispatched).toHaveLength(0)

    const clearButton = byId<HTMLButtonElement>('hilos-table-selection-clear')
    expect(clearButton).not.toBeNull()
    expect(clearButton?.disabled).toBe(false)
    fireEvent.click(clearButton as Element)
    expect(selectCheckbox?.checked).toBe(false)
  })

  it('an admin on a node in the mode keeps every control live', async () => {
    const { actions, dispatched } = makeActions()
    const context = seededContext([ARCHIVE_FIXTURE], actions, {
      backupRestore: { uiEnabled: true, targetEnv: 'dev' },
    })
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    mountPage(context)

    const keepSwitch = inTable<HTMLInputElement>(
      `hilos-backup-keep-${ARCHIVE_ID}`,
    )
    expect(keepSwitch?.disabled).toBe(false)
    expect(keepSwitch?.getAttribute('aria-describedby')).toBeNull()

    fireEvent.click(inTable(`hilos-backup-restore-${ARCHIVE_ID}`) as Element)
    fireEvent.change(byId('hilos-backup-restore-id') as Element, {
      target: { value: ARCHIVE_ID },
    })
    const restoreConfirm = byId<HTMLButtonElement>(
      'hilos-backup-restore-confirm',
    )
    expect(restoreConfirm?.disabled).toBe(false)
    fireEvent.click(byId('hilos-backup-restore-cancel') as Element)

    fireEvent.click(inTable(`hilos-backup-delete-${ARCHIVE_ID}`) as Element)
    const deleteConfirm = byId<HTMLButtonElement>('hilos-backup-delete-confirm')
    expect(deleteConfirm?.disabled).toBe(false)
    expect(deleteConfirm?.getAttribute('aria-describedby')).toBeNull()

    fireEvent.click(deleteConfirm as Element)
    await settled()

    expect(dispatched).toHaveLength(1)
    expect(dispatched[0]).toMatchObject({
      action: 'backup_delete',
      payload: { backupId: ARCHIVE_ID },
    })
  })
})

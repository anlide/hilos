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
  HilosRouter,
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
}

function seededContext(
  initial: BackupFixture[] = [ARCHIVE_FIXTURE],
  actions: ActionLifecycle = makeActions().actions,
  options: SeededOptions = {},
): {
  context: HilosBackupsContext
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

  const connection = {
    registerTableWindow(): void {
      serveWindow()
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

      return () => {}
    },
  }

  return {
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

async function mountPage(context: HilosBackupsContext) {
  const wrapper = mount(HilosBackupPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router() } },
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
    // The initial rows are applied table_window data. Hold later viewport answers:
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

  it('a viewer opens the reopen dialog and has nothing to reopen with', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions, {
      backupReopen: { offered: true },
    })
    await mountPage(context)

    const reopenButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-backup-reopen"]',
    )
    expect(reopenButton).not.toBeNull()
    expect(reopenButton?.disabled).toBe(false)
    reopenButton?.click()
    await nextTick()

    const reopenConfirm = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-backup-reopen-confirm"]',
    )
    expect(reopenConfirm).not.toBeNull()
    expect(reopenConfirm?.disabled).toBe(true)
    expect(reopenConfirm?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    reopenConfirm?.click()
    await settled()
    expect(dispatched).toHaveLength(0)

    const cancelButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-backup-reopen-cancel"]',
    )
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    cancelButton?.click()
    await settled()
    expect(
      document.querySelector('[data-id="hilos-backup-reopen-confirm"]'),
    ).toBeNull()
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

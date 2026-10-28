// The React peer of vue/src/admin/backup/HilosBackupPage.test.ts, the cases of the
// admin view mode (HIL-1260, HIL-1261, HIL-1264): the mark where a free text is
// hidden, and every control of the page a viewer finds standing — create, delete,
// keep, restore, reopen and the bulk delete — with Cancel and Clear alive.
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import {
  formatMoment,
  hilosBrowserTimeZone,
  hilosTimeZone,
} from '@hilos/core/base'
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosBackupsContext,
  HilosConnection,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'

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

/** A backups context whose window serves the given archives and whose actions are the given ones. */
function seededContext(
  initial: BackupFixture[] = [ARCHIVE_FIXTURE],
  actions: ActionLifecycle = makeActions().actions,
  options: SeededOptions = {},
): HilosBackupsContext {
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
    connection: connection as unknown as HilosBackupsContext['connection'],
    scopes,
    actions,
  }
}

function mountPage(context: HilosBackupsContext): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={router()}>
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

afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
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

  it('a viewer opens the reopen dialog and has nothing to reopen with', async () => {
    const { actions, dispatched } = makeActions()
    const context = seededContext([ARCHIVE_FIXTURE], actions, {
      backupReopen: { offered: true },
    })
    bindSession(context.scopes).handshake(null, true)
    mountPage(context)

    const reopenButton = byId<HTMLButtonElement>('hilos-backup-reopen')
    expect(reopenButton).not.toBeNull()
    expect(reopenButton?.disabled).toBe(false)
    fireEvent.click(reopenButton as Element)

    const reopenConfirm = byId<HTMLButtonElement>('hilos-backup-reopen-confirm')
    expect(reopenConfirm).not.toBeNull()
    expect(reopenConfirm?.disabled).toBe(true)
    expect(reopenConfirm?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(reopenConfirm as Element)
    await settled()
    expect(dispatched).toHaveLength(0)

    const cancelButton = byId<HTMLButtonElement>('hilos-backup-reopen-cancel')
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    fireEvent.click(cancelButton as Element)
    expect(byId('hilos-backup-reopen-confirm')).toBeNull()
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

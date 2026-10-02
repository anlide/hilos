// The React peer of vue/src/admin/backup/HilosBackupPage.test.ts, the cases of the
// admin view mode (HIL-1260, HIL-1261, HIL-1264): the mark where a free text is
// hidden, and every control of the page a viewer finds standing — create, delete,
// keep, restore, reopen and the bulk delete — with Cancel and Clear alive.
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionError,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
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
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

interface BackupSlot {
  createdAt: string
  env: string
  scope: string
  sizeBytes: number
  durationSeconds: number
  keep: boolean
  status: string
  finished: boolean
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
    createdAt: '2026-09-10T03:00:00+00:00',
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

describe('HilosBackupPage with the free texts hidden (HIL-1260)', () => {
  const HIDDEN = { _hidden: true }
  const FAILED_ID = '2026-09-11_03-00-00'
  const SHIP_FAILED_ID = '2026-09-12_03-00-00'

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
        } as unknown as BackupSlot,
      },
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

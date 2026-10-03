// The Angular peer of vue/src/admin/backup/HilosBackupPage.test.ts and
// react/test/HilosBackupPage.test.tsx, the cases of the admin view mode (HIL-1260,
// HIL-1261, HIL-1264): the mark where a free text is hidden, and every control of
// the page a viewer finds standing — create, delete, keep, restore, reopen and the
// bulk delete — with Cancel and Clear alive.
//
// The world below — the connection, the action lifecycle and the row on the wire —
// is the peers', moved over verbatim: it is written against @hilos/core and knows
// nothing about a view framework. What is Angular's own is the mount (TestBed) and
// the fact that a frame is read by running change detection rather than by
// awaiting a tick.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
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
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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

/** The wire row of one archive: the id is the row key, the rest rides the slot. */
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

/** One dispatched action, held open so the test times the answer the backend gives. */
interface Dispatched {
  action: string
  payload: Record<string, unknown>
  settle: (result: ActionResult) => void
  refuse: (error: ActionError) => void
}

/**
 * An action lifecycle that records what was dispatched and hands the answer back to
 * the test: a dialog closes on the server's word, so a fake that settled by itself
 * would hide exactly the step under test.
 *
 * @returns The lifecycle to mount with, and the log the assertions read.
 */
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

/**
 * A backups context whose window serves the given archives and whose actions are
 * the given ones.
 *
 * @param initial The archives the table's window carries.
 * @param actions The action lifecycle a dispatch travels through.
 * @param options What the page's own answer says about restoring and reopening.
 * @returns The context to mount the page with.
 */
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

/**
 * Mount the page with the router an app provides it with. `context` is a required
 * input, so it holds a value before the first change detection reads it.
 *
 * @param context The backups context the page is mounted with.
 * @returns The mounted fixture.
 */
function mountPage(
  context: HilosBackupsContext,
): ComponentFixture<HilosBackupPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosBackupPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()

  return fixture
}

/**
 * Let the microtasks a settled action resolves through run out, then render.
 *
 * @param fixture The mounted page to flush the render of.
 */
async function settled(
  fixture: ComponentFixture<HilosBackupPage>,
): Promise<void> {
  // The test's action promise is outside Angular's pending-task accounting:
  // let the driver and the submit continuation finish before waiting on render.
  await Promise.resolve()
  await Promise.resolve()
  await fixture.whenStable()
  fixture.detectChanges()
}

/**
 * Click a node and render what follows.
 *
 * @param fixture The mounted page to render after the click.
 * @param node The node to click, or null when the state does not draw it.
 */
function click(
  fixture: ComponentFixture<HilosBackupPage>,
  node: HTMLElement | null | undefined,
): void {
  node?.click()
  fixture.detectChanges()
}

/**
 * Put a value into a field the way typing does, and render what follows.
 *
 * @param fixture The mounted page to render after the change.
 * @param node The field, or null when the state does not draw it.
 * @param value What the field now holds.
 * @param event The event the page listens to on the field.
 * @throws Error When the state does not draw the field at all.
 */
function enter(
  fixture: ComponentFixture<HilosBackupPage>,
  node: HTMLInputElement | HTMLSelectElement | null,
  value: string,
  event: 'input' | 'change',
): void {
  if (node === null) {
    throw new Error('the page is not offering the field')
  }
  node.value = value
  node.dispatchEvent(new Event(event))
  fixture.detectChanges()
}

/**
 * The element standing under the id in the document. The Angular modal renders in
 * place rather than through a portal, so one query covers the page and its dialogs.
 *
 * @param id The `data-id` the page renders on the node.
 */
function byId<T extends HTMLElement = HTMLElement>(id: string): T | null {
  return document.querySelector<T>(`[data-id="${id}"]`)
}

/**
 * The element of a table row's control. A row stands in the document twice — once
 * in the table and once in the card the same row becomes on a narrow screen — so
 * the table is asked.
 *
 * @param id The `data-id` the row renders on the control.
 */
function inTable<T extends HTMLElement = HTMLElement>(id: string): T | null {
  return document.querySelector<T>(`table [data-id="${id}"]`)
}

describe('HilosBackupPage with the free texts hidden (HIL-1260)', () => {
  const HIDDEN = { _hidden: true }
  const FAILED_ID = '2026-09-11_03-00-00'
  const SHIP_FAILED_ID = '2026-09-12_03-00-00'

  /** The modal standing on the page, or null. */
  function modal(): HTMLElement | null {
    return byId('modal')
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
    const fixture = mountPage(context)

    click(fixture, inTable(`hilos-backup-details-${FAILED_ID}`))
    await settled(fixture)
    expect(
      modal()?.querySelector('[data-id="hilos-hidden"]')?.textContent?.trim(),
    ).toBe('Hidden')
    expect(
      modal()?.querySelector('[data-id="hilos-backup-details-text"]'),
    ).toBeNull()
    click(fixture, byId('hilos-backup-details-close'))
    await settled(fixture)

    click(fixture, inTable(`hilos-backup-ship-why-${SHIP_FAILED_ID}`))
    await settled(fixture)
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
   * Bind the session scope and the admin access the way bootHilos does, over
   * handshakes this harness emits.
   */
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
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions)
    const fixture = mountPage(context)

    const mainActionButton = byId<HTMLButtonElement>('hilos-table-main-action')
    expect(mainActionButton).not.toBeNull()
    expect(mainActionButton?.disabled).toBe(false)
    click(fixture, mainActionButton)

    const scopeSelect = byId<HTMLSelectElement>('hilos-backup-create-scope')
    expect(scopeSelect).not.toBeNull()
    expect(scopeSelect?.disabled).toBe(false)
    enter(fixture, scopeSelect, 'schema-only', 'change')
    expect(scopeSelect?.value).toBe('schema-only')

    const createConfirm = byId<HTMLButtonElement>('hilos-backup-create-confirm')
    expect(createConfirm).not.toBeNull()
    expect(createConfirm?.disabled).toBe(true)
    expect(createConfirm?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    click(fixture, createConfirm)
    await settled(fixture)
    expect(dispatched).toHaveLength(0)

    const cancelButton = byId<HTMLButtonElement>('hilos-backup-create-cancel')
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    click(fixture, cancelButton)
    await settled(fixture)
    expect(byId('hilos-backup-create-scope')).toBeNull()
  })

  it('a viewer opens the delete dialog over a row and has nothing to delete with', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions)
    const fixture = mountPage(context)

    const trashButton = inTable<HTMLButtonElement>(
      `hilos-backup-delete-${ARCHIVE_ID}`,
    )
    expect(trashButton).not.toBeNull()
    expect(trashButton?.disabled).toBe(false)
    click(fixture, trashButton)

    const deleteConfirm = byId<HTMLButtonElement>('hilos-backup-delete-confirm')
    expect(deleteConfirm).not.toBeNull()
    expect(deleteConfirm?.disabled).toBe(true)
    expect(deleteConfirm?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    click(fixture, deleteConfirm)
    await settled(fixture)
    expect(dispatched).toHaveLength(0)

    const cancelButton = byId<HTMLButtonElement>('hilos-backup-delete-cancel')
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    click(fixture, cancelButton)
    await settled(fixture)
    expect(byId('hilos-backup-delete-confirm')).toBeNull()
  })

  it('a viewer finds the keep switch standing where the server put it', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions)
    const fixture = mountPage(context)

    const keepSwitch = inTable<HTMLInputElement>(
      `hilos-backup-keep-${ARCHIVE_ID}`,
    )
    expect(keepSwitch).not.toBeNull()
    expect(keepSwitch?.disabled).toBe(true)
    expect(keepSwitch?.checked).toBe(true)
    expect(keepSwitch?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    click(fixture, keepSwitch)
    await settled(fixture)
    expect(dispatched).toHaveLength(0)
  })

  it('a viewer opens the restore dialog, types the archive id and has nothing to restore with', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions, {
      backupRestore: { uiEnabled: true, targetEnv: 'dev' },
    })
    const fixture = mountPage(context)

    const restoreButton = inTable<HTMLButtonElement>(
      `hilos-backup-restore-${ARCHIVE_ID}`,
    )
    expect(restoreButton).not.toBeNull()
    expect(restoreButton?.disabled).toBe(false)
    click(fixture, restoreButton)

    const idInput = byId<HTMLInputElement>('hilos-backup-restore-id')
    expect(idInput).not.toBeNull()
    expect(idInput?.disabled).toBe(false)
    enter(fixture, idInput, ARCHIVE_ID, 'input')

    const restoreConfirm = byId<HTMLButtonElement>(
      'hilos-backup-restore-confirm',
    )
    expect(restoreConfirm).not.toBeNull()
    expect(restoreConfirm?.disabled).toBe(true)
    expect(restoreConfirm?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    click(fixture, restoreConfirm)
    await settled(fixture)
    expect(dispatched).toHaveLength(0)

    const cancelButton = byId<HTMLButtonElement>('hilos-backup-restore-cancel')
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    click(fixture, cancelButton)
    await settled(fixture)
    expect(byId('hilos-backup-restore-id')).toBeNull()
  })

  it('a viewer opens the reopen dialog and has nothing to reopen with', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions, {
      backupReopen: { offered: true },
    })
    const fixture = mountPage(context)

    const reopenButton = byId<HTMLButtonElement>('hilos-backup-reopen')
    expect(reopenButton).not.toBeNull()
    expect(reopenButton?.disabled).toBe(false)
    click(fixture, reopenButton)

    const reopenConfirm = byId<HTMLButtonElement>('hilos-backup-reopen-confirm')
    expect(reopenConfirm).not.toBeNull()
    expect(reopenConfirm?.disabled).toBe(true)
    expect(reopenConfirm?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    click(fixture, reopenConfirm)
    await settled(fixture)
    expect(dispatched).toHaveLength(0)

    const cancelButton = byId<HTMLButtonElement>('hilos-backup-reopen-cancel')
    expect(cancelButton).not.toBeNull()
    expect(cancelButton?.disabled).toBe(false)
    click(fixture, cancelButton)
    await settled(fixture)
    expect(byId('hilos-backup-reopen-confirm')).toBeNull()
  })

  it('a viewer marks a row and finds the bulk delete standing in view mode', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions)
    const fixture = mountPage(context)

    const selectCheckbox = inTable<HTMLInputElement>(
      `hilos-table-select-${ARCHIVE_ID}`,
    )
    expect(selectCheckbox).not.toBeNull()
    click(fixture, selectCheckbox)
    expect(selectCheckbox?.checked).toBe(true)

    const bulkDelete = byId<HTMLButtonElement>('hilos-table-bulk-delete')
    expect(bulkDelete).not.toBeNull()
    expect(bulkDelete?.disabled).toBe(true)
    expect(bulkDelete?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    click(fixture, bulkDelete)
    expect(byId('hilos-table-bulk-confirm')).toBeNull()
    expect(dispatched).toHaveLength(0)

    const clearButton = byId<HTMLButtonElement>('hilos-table-selection-clear')
    expect(clearButton).not.toBeNull()
    expect(clearButton?.disabled).toBe(false)
    click(fixture, clearButton)
    expect(selectCheckbox?.checked).toBe(false)
  })

  it('an admin on a node in the mode keeps every control live', async () => {
    const { handshake } = bindSession()
    handshake({ id: 1, admin: true }, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions, {
      backupRestore: { uiEnabled: true, targetEnv: 'dev' },
    })
    const fixture = mountPage(context)

    const keepSwitch = inTable<HTMLInputElement>(
      `hilos-backup-keep-${ARCHIVE_ID}`,
    )
    expect(keepSwitch?.disabled).toBe(false)
    expect(keepSwitch?.getAttribute('aria-describedby')).toBeNull()

    click(fixture, inTable(`hilos-backup-restore-${ARCHIVE_ID}`))
    enter(
      fixture,
      byId<HTMLInputElement>('hilos-backup-restore-id'),
      ARCHIVE_ID,
      'input',
    )
    const restoreConfirm = byId<HTMLButtonElement>(
      'hilos-backup-restore-confirm',
    )
    expect(restoreConfirm?.disabled).toBe(false)
    click(fixture, byId('hilos-backup-restore-cancel'))
    await settled(fixture)

    click(fixture, inTable(`hilos-backup-delete-${ARCHIVE_ID}`))
    const deleteConfirm = byId<HTMLButtonElement>('hilos-backup-delete-confirm')
    expect(deleteConfirm?.disabled).toBe(false)
    expect(deleteConfirm?.getAttribute('aria-describedby')).toBeNull()

    click(fixture, deleteConfirm)
    await settled(fixture)

    expect(dispatched).toHaveLength(1)
    expect(dispatched[0]).toMatchObject({
      action: 'backup_delete',
      payload: { backupId: ARCHIVE_ID },
    })
  })
})

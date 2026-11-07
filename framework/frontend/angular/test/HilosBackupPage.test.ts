// The Angular peer of vue/src/admin/backup/HilosBackupPage.test.ts and
// react/test/HilosBackupPage.test.tsx, the cases of the admin view mode (HIL-1260,
// HIL-1261, HIL-1264): the mark where a free text is hidden, and every control of
// the page a viewer finds standing — create, delete, keep, restore and bulk delete —
// with navigation, Cancel and Clear alive.
//
// The world below — the connection, the action lifecycle and the row on the wire —
// is the peers', moved over verbatim: it is written against @hilos/core and knows
// nothing about a view framework. What is Angular's own is the mount (TestBed) and
// the fact that a frame is read by running change detection rather than by
// awaiting a tick.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { By } from '@angular/platform-browser'
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
import {
  BACKUP_RESTORE_PROGRESS_SIGNAL,
  type HilosRestoreStatus,
} from '@hilos/core/admin/backup'
import { formatMoment, type HilosTableProgressFrame } from '@hilos/core/base'

import { HilosBackupPage } from '../src/admin/backup/HilosBackupPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { bindTestTimeZone } from './timeZoneTestHarness.js'

const timeZoneReleases: (() => void)[] = []

afterEach(() => {
  for (const release of timeZoneReleases.splice(0)) release()
})

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
    createdAt: '2026-09-10T03:00:00.000Z',
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
  progress?: readonly HilosTableProgressFrame[]
}

interface SeededContext {
  context: HilosBackupsContext
  pushUpdate: (item: BackupFixture) => void
  updatePageResponse: () => void
  updateProgress: (progress: HilosTableProgressFrame) => void
  updateRestoreStatus: (status: HilosRestoreStatus) => void
}

/**
 * A backups context whose window serves the given archives and whose actions are
 * the given ones.
 *
 * @param initial The archives the table's window carries.
 * @param actions The action lifecycle a dispatch travels through.
 * @param options What the page's own answer says about restoring and reopening.
 * @returns The context and emitters for page data, rows, table progress, and restore progress.
 */
function seededContext(
  initial: BackupFixture[] = [ARCHIVE_FIXTURE],
  actions: ActionLifecycle = makeActions().actions,
  options: SeededOptions = {},
): SeededContext {
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
  const projectListeners = new Set<(signal: ProjectSignal) => void>()
  const pushUpdate = (item: BackupFixture): void => {
    const index = rows.findIndex((row) => row.id === item.id)
    if (index !== -1) rows[index] = item
    for (const listener of deltaListeners) {
      listener({
        data: {
          page: HilosPages.BACKUP,
          tableKey: BACKUPS_TABLE,
          kind: 'row_updated',
          rowKey: item.id,
          row: wireRow(item),
        },
      })
    }
  }
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
      if (event === 'tableProgress') {
        progressListeners.add(listener)

        return () => progressListeners.delete(listener)
      }
      if (event === 'projectSignal') {
        const projectListener = listener as (signal: ProjectSignal) => void
        projectListeners.add(projectListener)

        return () => projectListeners.delete(projectListener)
      }

      return () => {}
    },
  }
  const updatePageResponse = (): void => {
    const data = {
      page: HilosPages.BACKUP,
      payload: {
        windows: {
          [BACKUPS_TABLE]: {
            filter: {},
            rows: rows.map(wireRow),
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 10,
            sort: [{ field: 'createdAt', direction: 'desc' }],
            progress: options.progress ?? [],
          },
        },
      },
    }
    for (const listener of projectListeners) {
      listener({
        kind: 'project',
        type: 'page_response',
        data,
        envelope: {},
      } as unknown as ProjectSignal)
    }
  }

  return {
    context: {
      connection: connection as unknown as HilosBackupsContext['connection'],
      scopes,
      actions,
    },
    pushUpdate,
    updatePageResponse,
    updateProgress(progress): void {
      for (const listener of progressListeners) {
        listener({
          data: {
            ...progress,
            page: HilosPages.BACKUP,
            tableKey: BACKUPS_TABLE,
          },
        })
      }
    },
    updateRestoreStatus(status): void {
      for (const listener of projectListeners) {
        listener({
          kind: 'project',
          type: BACKUP_RESTORE_PROGRESS_SIGNAL,
          data: status,
          envelope: {},
        } as unknown as ProjectSignal)
      }
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
  pageRouter: HilosRouter = router(),
): ComponentFixture<HilosBackupPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: pageRouter }],
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
 * The element standing under the id in the document. This finds page modals that
 * render in place and the action-reason modal that HilosActionReason relocates.
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

/**
 * The card projection of the backup row's control.
 *
 * @param id The `data-id` the card renders on the control.
 */
function inCard<T extends HTMLElement = HTMLElement>(id: string): T | null {
  return document.querySelector<T>(
    `[data-id="hilos-table-card-${ARCHIVE_ID}"] [data-id="${id}"]`,
  )
}

describe('HilosBackupPage with the free texts hidden (HIL-1260)', () => {
  const HIDDEN = { _hidden: true }
  const FAILED_ID = '2026-09-11_03-00-00'
  const SHIP_FAILED_ID = '2026-09-12_03-00-00'
  const LEGACY_FAILED_ID = '2026-09-13_03-00-00'

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
    const fixture = mountPage(context)

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

describe('HilosBackupPage why a restore is blocked', () => {
  const RESTORE_GATE = {
    backupRestore: { uiEnabled: true, targetEnv: 'dev' },
  }
  const OUT_OF_REACH_REASON =
    'Stored on node node-2 - the backup agent runs elsewhere, so this archive cannot be reached from here'
  const HIDDEN = { _hidden: true }

  it('names the holder in the badge and explanation as text, falls back to its id and keeps remote archives unavailable', () => {
    const { actions, dispatched } = makeActions()
    const remote: BackupFixture = {
      ...ARCHIVE_FIXTURE,
      backup: {
        ...ARCHIVE_FIXTURE.backup,
        holderNode: 'node-2',
        holderNodeName: 'Київ <b>倉庫</b> & "two"',
      },
    }
    const { context, pushUpdate } = seededContext(
      [remote],
      actions,
      RESTORE_GATE,
    )
    const fixture = mountPage(context)

    for (const [name, label] of [
      ['Київ <b>倉庫</b> & "two"', 'Київ <b>倉庫</b> & "two" (node-2)'],
      [null, 'node-2'],
    ] as const) {
      pushUpdate({
        ...remote,
        backup: { ...remote.backup, holderNodeName: name },
      })
      fixture.detectChanges()
      const reason = `Stored on node ${label} - the backup agent runs elsewhere, so this archive cannot be reached from here`

      for (const projection of [inTable, inCard]) {
        const badge = projection(`hilos-backup-holder-${ARCHIVE_ID}`)
        expect(badge?.textContent?.trim()).toBe(label)
        expect(badge?.getAttribute('title')).toBe(reason)
        expect(badge?.querySelector('b')).toBeNull()
        expect(badge?.parentElement?.textContent).toContain(reason)
        expect(
          projection<HTMLButtonElement>(`hilos-backup-restore-${ARCHIVE_ID}`)
            ?.disabled,
        ).toBe(true)
        expect(projection(`hilos-backup-delete-${ARCHIVE_ID}`)).toBeNull()
        expect(projection(`hilos-backup-keep-${ARCHIVE_ID}`)).toBeNull()
        expect(
          projection<HTMLButtonElement>('hilos-action-reason')?.title,
        ).toBe(reason)
      }

      click(fixture, inTable('hilos-action-reason'))
      expect(byId('hilos-action-reason-body')?.textContent?.trim()).toBe(reason)
      expect(byId('hilos-action-reason-body')?.querySelector('b')).toBeNull()
      expect(byId('hilos-backup-restore-confirm')).toBeNull()
      click(fixture, byId('hilos-action-reason-close'))
    }

    pushUpdate(ARCHIVE_FIXTURE)
    fixture.detectChanges()
    expect(byId(`hilos-backup-holder-${ARCHIVE_ID}`)).toBeNull()
    expect(
      inTable<HTMLButtonElement>(`hilos-backup-restore-${ARCHIVE_ID}`)
        ?.disabled,
    ).toBe(false)
    expect(dispatched).toHaveLength(0)
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
    'explains %s from the row and card without opening restore',
    (_scenario, backupFields, reason) => {
      const { actions, dispatched } = makeActions()
      const { context } = seededContext(
        [
          {
            ...ARCHIVE_FIXTURE,
            backup: { ...ARCHIVE_FIXTURE.backup, ...backupFields },
          },
        ],
        actions,
        RESTORE_GATE,
      )
      const fixture = mountPage(context)

      const restore = inTable<HTMLButtonElement>(
        `hilos-backup-restore-${ARCHIVE_ID}`,
      )
      const opener = inTable<HTMLButtonElement>('hilos-action-reason')
      const cardOpener = inCard<HTMLButtonElement>('hilos-action-reason')
      expect(restore?.disabled).toBe(true)
      expect(opener?.type).toBe('button')
      expect(opener?.disabled).toBe(false)
      expect(opener?.getAttribute('aria-label')).toBe(
        'Why this backup cannot be restored',
      )
      expect(opener?.title).toBe(reason)
      expect(cardOpener?.getAttribute('aria-label')).toBe(
        'Why this backup cannot be restored',
      )
      expect(
        inCard<HTMLButtonElement>(`hilos-backup-restore-${ARCHIVE_ID}`)
          ?.disabled,
      ).toBe(true)

      opener!.focus()
      click(fixture, opener)

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

      click(fixture, byId('hilos-action-reason-close'))
      expect(byId('modal')).toBeNull()
      expect(document.activeElement).toBe(opener)
    },
  )

  it('shows a hidden migration notice through the mark without leaking it', () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext(
      [
        {
          ...ARCHIVE_FIXTURE,
          backup: {
            ...ARCHIVE_FIXTURE.backup,
            restoreMigrationDecision: 'refuse',
            restoreMigrationNotice: HIDDEN,
          },
        },
      ],
      actions,
      RESTORE_GATE,
    )
    const fixture = mountPage(context)

    const opener = inTable<HTMLButtonElement>('hilos-action-reason')
    expect(
      inTable<HTMLButtonElement>(`hilos-backup-restore-${ARCHIVE_ID}`)
        ?.disabled,
    ).toBe(true)
    expect(opener?.hasAttribute('title')).toBe(false)
    expect(opener?.getAttribute('aria-label')).toBe(
      'Why this backup cannot be restored',
    )

    click(fixture, opener)

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

  it('follows live restore eligibility and keeps the open explanation snapshot', () => {
    const { actions, dispatched } = makeActions()
    const checksumBlocked: BackupFixture = {
      ...ARCHIVE_FIXTURE,
      backup: { ...ARCHIVE_FIXTURE.backup, checksumState: 'mismatch' },
    }
    const outOfReach: BackupFixture = {
      ...ARCHIVE_FIXTURE,
      backup: { ...ARCHIVE_FIXTURE.backup, holderNode: 'node-2' },
    }
    const { context, pushUpdate } = seededContext(
      [checksumBlocked],
      actions,
      RESTORE_GATE,
    )
    const fixture = mountPage(context)

    const opener = inTable<HTMLButtonElement>('hilos-action-reason')
    expect(opener?.title).toBe(
      'This archive does not match its recorded checksum',
    )
    click(fixture, opener)
    const dialog = byId('modal')
    const body = byId('hilos-action-reason-body')
    expect(body?.textContent?.trim()).toBe(
      'This archive does not match its recorded checksum',
    )

    pushUpdate(ARCHIVE_FIXTURE)
    fixture.detectChanges()
    expect(
      inTable<HTMLButtonElement>(`hilos-backup-restore-${ARCHIVE_ID}`)
        ?.disabled,
    ).toBe(false)
    expect(inTable<HTMLButtonElement>('hilos-action-reason')?.hidden).toBe(true)
    expect(byId('modal')).toBe(dialog)
    expect(body?.textContent?.trim()).toBe(
      'This archive does not match its recorded checksum',
    )
    expect(byId('hilos-backup-restore-confirm')).toBeNull()

    click(fixture, byId('hilos-action-reason-close'))
    pushUpdate(outOfReach)
    fixture.detectChanges()

    const freshOpener = inTable<HTMLButtonElement>('hilos-action-reason')
    expect(freshOpener?.hidden).toBe(false)
    expect(freshOpener?.title).toBe(OUT_OF_REACH_REASON)
    click(fixture, freshOpener)
    expect(byId('hilos-action-reason-body')?.textContent?.trim()).toBe(
      OUT_OF_REACH_REASON,
    )
    expect(byId('hilos-backup-restore-confirm')).toBeNull()
    expect(dispatched).toHaveLength(0)
  })

  it('hides the reason opener for a restorable archive while enabling restore', () => {
    const { context } = seededContext(
      [ARCHIVE_FIXTURE],
      undefined,
      RESTORE_GATE,
    )
    const fixture = mountPage(context)

    expect(
      inTable<HTMLButtonElement>(`hilos-backup-restore-${ARCHIVE_ID}`)
        ?.disabled,
    ).toBe(false)
    expect(inTable<HTMLButtonElement>('hilos-action-reason')?.hidden).toBe(true)
    expect(byId('hilos-backup-restore-confirm')).toBeNull()
    fixture.destroy()
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
    expect(scopeSelect?.value).toBe('full')
    fixture.debugElement
      .query(By.css('[data-id="hilos-backup-create-scope"]'))
      .triggerEventHandler('change', { target: { value: 'schema-only' } })
    fixture.detectChanges()
    expect(scopeSelect?.value).toBe('schema-only')
    expect(scopeSelect?.selectedOptions[0]?.value).toBe('schema-only')

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
    click(fixture, mainActionButton)
    await settled(fixture)
    const reopened = byId<HTMLSelectElement>('hilos-backup-create-scope')
    expect(reopened?.value).toBe('full')
    expect(reopened?.selectedOptions[0]?.value).toBe('full')
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

  it.each([
    { label: 'no offered value', backupReopen: undefined },
    { label: 'offered=false', backupReopen: { offered: false } },
  ])('hides verification navigation with $label', ({ backupReopen }) => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions, {
      backupReopen,
    })
    const fixture = mountPage(context)

    expect(byId('hilos-backup-reopen-panel')).toBeNull()
    expect(byId('hilos-backup-reopen')).toBeNull()
    expect(dispatched).toHaveLength(0)
    fixture.destroy()
  })

  it('withdraws verification navigation when the personal offer is removed live', async () => {
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions, {
      backupReopen: { offered: true },
    })
    const fixture = mountPage(context)

    expect(byId('hilos-backup-reopen-panel')).not.toBeNull()
    expect(inTable(`hilos-table-row-${ARCHIVE_ID}`)).not.toBeNull()
    context.scopes.page()!.data.set('backupReopen', { offered: false })
    await settled(fixture)

    expect(byId('hilos-backup-reopen-panel')).toBeNull()
    expect(byId('hilos-backup-reopen')).toBeNull()
    expect(inTable(`hilos-table-row-${ARCHIVE_ID}`)).not.toBeNull()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(dispatched).toHaveLength(0)
  })

  it('a viewer follows Maintenance and Circle links without opening a mutation dialog', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context } = seededContext([ARCHIVE_FIXTURE], actions, {
      backupReopen: { offered: true },
    })
    const pageRouter = router()
    const navigate = vi.spyOn(pageRouter, 'navigate')
    const fixture = mountPage(context, pageRouter)

    const panel = byId('hilos-backup-reopen-panel')
    expect(panel?.getAttribute('role')).toBe('status')
    expect(panel?.textContent).toContain('Verification window open')
    expect(panel?.textContent).toContain(
      'The verification window is open and the system remains closed to visitors. ' +
        'Check the system, then open it in Maintenance. Every connected page reloads when you do.',
    )
    const maintenance = byId<HTMLAnchorElement>('hilos-backup-reopen')
    expect(maintenance?.tagName).toBe('A')
    expect(maintenance?.getAttribute('href')).toBe('/hilos/maintenance')
    expect(maintenance?.hasAttribute('aria-disabled')).toBe(false)
    expect(maintenance?.hasAttribute('aria-describedby')).toBe(false)
    click(fixture, maintenance)
    await settled(fixture)
    expect(navigate).toHaveBeenCalledExactlyOnceWith('/hilos/maintenance')
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(byId('hilos-backup-reopen-confirm')).toBeNull()

    const circle = byId<HTMLAnchorElement>('hilos-backup-circle-pointer-open')
    expect(circle?.tagName).toBe('A')
    expect(circle?.getAttribute('href')).toBe('/hilos/maintenance/circle')
    expect(circle?.textContent).toContain('Open Maintenance')
    expect(byId('hilos-backup-circle-pointer')?.textContent).toContain(
      'The verifier circle is kept at /hilos/maintenance/circle.',
    )
    expect(circle?.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    click(fixture, circle)
    expect(navigate).toHaveBeenLastCalledWith('/hilos/maintenance/circle')
    expect(navigate).toHaveBeenCalledTimes(2)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(dispatched).toHaveLength(0)
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

describe('HilosBackupPage failed restore', () => {
  it.each([
    { databaseTouched: false, rehydrateComplete: false },
    { databaseTouched: true, rehydrateComplete: false },
    { databaseTouched: false, rehydrateComplete: true },
    { databaseTouched: true, rehydrateComplete: true },
  ])(
    'reports databaseTouched=$databaseTouched and rehydrateComplete=$rehydrateComplete independently',
    async (state) => {
      const { context, updateRestoreStatus } = seededContext()
      const fixture = mountPage(context)
      updateRestoreStatus({
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
        rehydrateProblems: [],
        ...state,
      })
      await settled(fixture)

      const panel = byId('hilos-backup-restore-phase')
      expect(panel?.getAttribute('role')).toBe('status')
      expect(panel?.textContent).toContain('tar: unexpected end of file')
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
      expect(
        Array.from(panel?.querySelectorAll('code') ?? []).map((code) =>
          code.textContent?.trim(),
        ),
      ).toEqual(
        state.rehydrateComplete
          ? []
          : ['/hilos/maintenance', 'php cli.php protected-mode:open'],
      )
      expect(panel?.querySelector('[role="progressbar"]')).toBeNull()
    },
  )
})

describe('HilosBackupPage table progress', () => {
  it('names the backup in the initial progress caption and status, follows updates, then clears the ended run', async () => {
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
    const { context, updatePageResponse, updateProgress } = seededContext(
      [],
      undefined,
      {
        progress: [initial],
      },
    )
    const fixture = mountPage(context)
    updatePageResponse()
    await settled(fixture)
    const title = `Backup ${ARCHIVE_ID} · dev · full`

    expect(
      byId('hilos-table-progress')?.querySelector('strong')?.textContent,
    ).toBe(title)
    expect(byId('hilos-table-progress')?.textContent).toContain(
      `${title} — dumping · 25% · ~12s left`,
    )
    expect(byId('hilos-table-live-status')?.getAttribute('role')).toBe('status')
    expect(byId('hilos-table-live-status')?.textContent).toBe(
      `${title} — dumping · 25% · ~12s left`,
    )

    updateProgress({
      ...initial,
      current: 75,
      detail: { ...initial.detail, phase: 'archiving', remainingSeconds: 4 },
    })
    await settled(fixture)
    expect(byId('hilos-table-progress')?.textContent).toContain(
      `${title} — archiving · 75% · ~4s left`,
    )
    expect(byId('hilos-table-live-status')?.textContent).toBe(
      `${title} — archiving · 75% · ~4s left`,
    )

    updateProgress({ ...initial, ended: true })
    await settled(fixture)
    expect(byId('hilos-table-progress')).toBeNull()
    expect(byId('hilos-table-live-status')?.textContent).toBe('')
    expect(document.body.textContent).not.toContain(title)
  })

  it('keeps old frames without a backup id readable and falls back for missing environment and scope', async () => {
    const { context, updatePageResponse, updateProgress } = seededContext(
      [],
      undefined,
      {
        progress: [
          {
            scope: 'table',
            progressKey: 'legacy',
            current: 0,
            detail: { phase: 'dumping', remainingSeconds: 12 },
          },
        ],
      },
    )
    const fixture = mountPage(context)
    updatePageResponse()
    await settled(fixture)

    expect(byId('hilos-table-progress')?.querySelector('strong')).toBeNull()
    expect(byId('hilos-table-progress')?.textContent?.trim()).toBe('dumping')
    expect(byId('hilos-table-live-status')?.textContent).toBe('dumping')

    updateProgress({
      scope: 'table',
      progressKey: 'legacy',
      current: 0,
      detail: {
        backupId: ARCHIVE_ID,
        phase: 'archiving',
        remainingSeconds: 10,
      },
    })
    await settled(fixture)
    expect(
      byId('hilos-table-progress')?.querySelector('strong')?.textContent,
    ).toBe(`Backup ${ARCHIVE_ID} · — · —`)
    expect(byId('hilos-table-progress')?.textContent).toContain(
      `Backup ${ARCHIVE_ID} · — · — — archiving`,
    )
    expect(byId('hilos-table-live-status')?.textContent).toBe(
      `Backup ${ARCHIVE_ID} · — · — — archiving`,
    )
  })
})

describe('HilosBackupPage moments', () => {
  it('reformats archive and restore moments in the reader zone while the outcome stays open', async () => {
    const timeZone = bindTestTimeZone('UTC')
    timeZoneReleases.push(timeZone.release)
    timeZone.receive('UTC')

    const createdAt = '2026-09-10T00:30:00.000Z'
    const verifiedAt = '2026-09-10T00:10:00.000Z'
    const shippedAt = '2026-09-10T00:20:00.000Z'
    const restoreFinishedAt = '2026-09-10T00:30:00.000Z'
    const backup: BackupFixture = {
      ...ARCHIVE_FIXTURE,
      backup: {
        ...ARCHIVE_FIXTURE.backup,
        createdAt,
        verifiedAt,
        shipState: 'shipped',
        shippedAt,
        restoreOutcome: 'success',
        restoreFinishedAt,
      },
    }
    const { context } = seededContext([backup])
    const fixture = mountPage(context)
    const row = document.querySelector<HTMLElement>(
      `table [data-id="hilos-table-row-${ARCHIVE_ID}"]`,
    )

    const createdBefore = formatMoment(createdAt, { kind: 'dateTime' }) ?? '—'
    const verifiedBefore = formatMoment(verifiedAt, { kind: 'date' }) ?? '—'
    const shippedBefore = formatMoment(shippedAt, { kind: 'date' }) ?? '—'
    expect(
      row?.querySelector('[data-id="hilos-backup-created-at"]')?.textContent,
    ).toContain(createdBefore)
    expect(
      row?.querySelector('[data-id="hilos-backup-checksum"]')?.textContent,
    ).toBe(`✓ ${verifiedBefore}`)
    expect(
      row?.querySelector('[data-id="hilos-backup-shipping"]')?.textContent,
    ).toBe(`✓ ${shippedBefore}`)

    click(fixture, inTable(`hilos-backup-restore-outcome-${ARCHIVE_ID}`))
    const finished = byId('hilos-backup-restore-outcome-finished')
    const dialog = finished?.closest('[role="dialog"]')
    const restoreBefore = formatMoment(restoreFinishedAt, { kind: 'dateTime' })
    expect(finished?.textContent?.trim()).toBe(
      `Finished ${restoreBefore} · success`,
    )
    expect(dialog).not.toBeNull()

    timeZone.receive('America/Los_Angeles')
    await fixture.whenStable()
    fixture.detectChanges()

    const createdAfter = formatMoment(createdAt, { kind: 'dateTime' }) ?? '—'
    const verifiedAfter = formatMoment(verifiedAt, { kind: 'date' }) ?? '—'
    const shippedAfter = formatMoment(shippedAt, { kind: 'date' }) ?? '—'
    const restoreAfter = formatMoment(restoreFinishedAt, { kind: 'dateTime' })
    const rowAfter = document.querySelector<HTMLElement>(
      `table [data-id="hilos-table-row-${ARCHIVE_ID}"]`,
    )
    expect(createdAfter).not.toBe(createdBefore)
    expect(verifiedAfter).not.toBe(verifiedBefore)
    expect(shippedAfter).not.toBe(shippedBefore)
    expect(restoreAfter).not.toBe(restoreBefore)
    expect(
      rowAfter?.querySelector('[data-id="hilos-backup-created-at"]')
        ?.textContent,
    ).toContain(createdAfter)
    expect(
      rowAfter?.querySelector('[data-id="hilos-backup-checksum"]')?.textContent,
    ).toBe(`✓ ${verifiedAfter}`)
    expect(
      rowAfter?.querySelector('[data-id="hilos-backup-shipping"]')?.textContent,
    ).toBe(`✓ ${shippedAfter}`)
    expect(finished?.textContent?.trim()).toBe(
      `Finished ${restoreAfter} · success`,
    )
    expect(byId('hilos-backup-restore-outcome-finished')).toBe(finished)
    expect(finished?.closest('[role="dialog"]')).toBe(dialog)
  })
})

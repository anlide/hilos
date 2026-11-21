import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  type TableViewportController,
  ActionLifecycle,
  ActionError,
  type ActionResult,
  applyServerTime,
  formatCalendarDate,
  HIDDEN_VALUE,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosConnection,
  HilosPages,
  hilosToasts,
  resolveHilosPath,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  entityCollection,
  formatMoment,
  USER_ENTITY_TYPE,
  userFromFields,
} from '@hilos/core/base'
import type {
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core/base'
import {
  HILOS_USER_MERGE_SECTION,
  HILOS_USER_MERGE_AVAILABILITY_SECTION,
  HILOS_ACCOUNT_MERGE_WINDOW_COPY,
  type HilosAccountMergeProgress,
  HILOS_USER_ERASURE_SECTION,
  type HilosUsersContext,
} from '@hilos/core/admin/users'

import { HilosUserPage } from '../src/admin/users/HilosUserPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { readerTimeZone } from './readerTimeZone.js'

const readerTimeZoneReleases: Array<() => void> = []

function router(overrides: Partial<HilosRouter> = {}): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: '',
      params: { userId: '1' },
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
    ...overrides,
  }
}

// The single-user detail arrives as the one row of the `userDetail` table; when
// `seed` is false the table is empty and the page shows its loading state. The
// name rides the `user` entity, so a rename elsewhere is an entity upsert and a
// deleted user is the row leaving the table.
/** The refusal a window meets when its administrator has nothing to confirm with. */
const STEP_UP_REFUSAL =
  'Add a password, an email or a phone to your account to do this'

/**
 * The card's context. A window that takes something away opens on the server's
 * word about the administrator's confirmation (HIL-1275); `stepUp` is that word.
 */
function userContext(
  seed: boolean,
  accountMerge = false,
  stepUp: 'skip' | 'ask' | 'refused' = 'skip',
  options: {
    detailHasPassword?: boolean
    detailHasSecondFactor?: boolean
    candidateHasSecondFactor?: boolean
    detailUnverifiedPasswordAddress?: string | null
    candidateHasPassword?: boolean
    candidateUnverifiedPasswordAddress?: string | null
    candidateNameHidden?: boolean
    candidateIdentitiesHidden?: boolean
    impersonation?: Record<string, unknown>
    currentUserId?: number
    candidateAvailable?: boolean
  } = {},
): HilosUsersContext & {
  renameElsewhere: (name: string) => void
  removeRow: () => void
  failRename: () => void
  standingFrame: (data: Record<string, unknown>) => void
  answerImpersonate: (reason?: string) => void
  answerMerge: (reason?: string, reply?: unknown) => void
  answerMergeCancel: (reason?: string, requestId?: string) => void
  updateCandidate: (fields: Record<string, unknown>) => void
  disconnect: () => void
  removeCandidate: () => void
  sent: Array<{ action: string; data: unknown; requestId?: string }>
} {
  const scopes = new ScopeManager()
  const page = scopes.openPage('hilos_user')
  page.data.set(HILOS_USER_MERGE_AVAILABILITY_SECTION, {
    reserved: false,
    available: true,
  })
  // The installation's impersonation settings ride the card's first answer (HIL-1170).
  if (options.impersonation !== undefined) {
    page.data.set('impersonation', options.impersonation)
  }
  if (options.currentUserId !== undefined) {
    scopes.session.data.set('currentUser', {
      type: 'user',
      id: options.currentUserId,
    })
  }
  if (seed) {
    page.entities.upsert(
      { type: 'user', id: 1 },
      { id: 1, name: 'Alice', lastActivity: 1781863200000 },
    )
    page.tables.upsert('userDetail', 1, {
      users: { type: 'user', id: 1 },
      connections: { presence: 'online', onlineSessionCount: 2 },
      secondFactors: {
        hasSecondFactor: options.detailHasSecondFactor ?? false,
      },
      identities: {
        hasPassword: options.detailHasPassword ?? false,
        unverifiedPasswordAddress:
          options.detailUnverifiedPasswordAddress ?? null,
      },
    })
  }
  const users = entityCollection(scopes, USER_ENTITY_TYPE, userFromFields)
  page.entities.upsert(
    { type: 'user', id: 2 },
    {
      id: 2,
      name: options.candidateNameHidden ? { _hidden: true } : 'Bob',
      lastActivity: null,
    },
  )
  const connection = new HilosConnection({ url: 'ws://test/ws' })
  const sent: Array<{ action: string; data: unknown; requestId?: string }> = []
  const actionListeners = new Map<string, Array<(signal: unknown) => void>>()
  const candidateFields: Record<string, unknown> = {}
  const candidateRows = () => [
    {
      rowKey: 2,
      slots: {
        users: { type: 'user', id: 2 },
        merge: {
          identities: options.candidateIdentitiesHidden
            ? { _hidden: true }
            : [
                {
                  type: 'email',
                  identifier: 'bob@example.test',
                  provider: null,
                  verified: true,
                },
              ],
          hasPassword: options.candidateHasPassword ?? false,
          hasSecondFactor: options.candidateHasSecondFactor ?? false,
          unverifiedPasswordAddress:
            options.candidateUnverifiedPasswordAddress ?? null,
          accountMergeAvailability: {
            reserved: options.candidateAvailable === false,
            available: options.candidateAvailable !== false,
          },
          ...candidateFields,
        },
      },
    },
    {
      rowKey: 99,
      slots: {
        users: { id: 99, name: 'Admin', lastActivity: null },
        merge: {
          identities: [],
          hasPassword: false,
          hasSecondFactor: false,
          accountMergeAvailability: { reserved: false, available: true },
        },
      },
    },
  ]
  const tableWindowListeners: Array<(signal: unknown) => void> = []
  const tableDeltaListeners: Array<(signal: unknown) => void> = []
  const stateListeners: Array<(signal: unknown) => void> = []
  const serveCandidates = (requestId?: string): void => {
    const signal = {
      kind: 'tableWindow',
      data: {
        page: HilosPages.USER,
        tableKey: 'mergeCandidates',
        requestId,
        rows: candidateRows(),
        totalCount: 2,
        totalExact: true,
        firstAnchor: null,
        lastAnchor: null,
        offset: 0,
        limit: 10,
      },
    }
    for (const listener of tableWindowListeners) {
      listener(signal)
    }
  }
  const registerWindow = connection.registerTableWindow.bind(connection)
  vi.spyOn(connection, 'registerTableWindow').mockImplementation(
    (key, source) => {
      registerWindow(key, source)
      if (key === 'mergeCandidates')
        (source as TableViewportController<unknown>).ingestSubscriptionWindow(
          [],
          0,
          true,
          null,
          null,
          10,
          undefined,
          {},
          [],
        )
    },
  )
  vi.spyOn(connection, 'sendTableViewport').mockImplementation(
    (_page, _table, descriptor) => {
      serveCandidates(descriptor.requestId)

      return true
    },
  )
  // The server's word on the step, answered on the next microtask as a socket would.
  const answerStepUp = (action: string, requestId?: string): void => {
    const refused = action === 'hilos_step_up_start' && stepUp === 'refused'
    const signal = refused
      ? { kind: 'actionError', action, requestId, reason: STEP_UP_REFUSAL }
      : {
          kind: 'actionSuccess',
          action,
          requestId,
          reply:
            action === 'hilos_step_up_start'
              ? {
                  required: stepUp === 'ask',
                  purpose: 'grant admin rights',
                  method: 'password',
                }
              : undefined,
        }
    for (const listener of actionListeners.get(signal.kind) ?? []) {
      listener(signal)
    }
  }
  vi.spyOn(connection, 'sendAction').mockImplementation(
    (action, data, requestId) => {
      sent.push({ action, data, requestId })
      if (action.startsWith('hilos_step_up_')) {
        queueMicrotask(() => answerStepUp(action, requestId))
      }

      return true
    },
  )
  // The rename's fail ack arrives as an unknown signal; keep its listeners to
  // answer a refusal.
  const unknownListeners: Array<(signal: { type: string }) => void> = []
  // The live standing frame (HIL-945) arrives as a project signal.
  const projectListeners: Array<(signal: Record<string, unknown>) => void> = []
  const on = connection.on.bind(connection)
  vi.spyOn(connection, 'on').mockImplementation((event, listener) => {
    if (event === 'unknownSignal') {
      unknownListeners.push(listener as (signal: { type: string }) => void)
    }
    if (event === 'projectSignal') {
      projectListeners.push(
        listener as (signal: Record<string, unknown>) => void,
      )
    }
    if (event === 'state')
      stateListeners.push(listener as (signal: unknown) => void)
    if (event === 'tableWindow') {
      tableWindowListeners.push(listener as (signal: unknown) => void)
    }
    if (event === 'tableViewportDelta') {
      tableDeltaListeners.push(listener as (signal: unknown) => void)
    }
    if (event === 'actionSuccess' || event === 'actionError') {
      actionListeners.set(event, [
        ...(actionListeners.get(event) ?? []),
        listener as (signal: unknown) => void,
      ])
    }

    return on(event, listener)
  })

  return {
    scopes,
    connection,
    actions: new ActionLifecycle(connection),
    users,
    accountMerge,
    renameElsewhere(name: string): void {
      page.entities.upsert({ type: 'user', id: 1 }, { name })
    },
    removeRow(): void {
      page.tables.delete('userDetail', 1)
    },
    failRename(): void {
      for (const listener of unknownListeners) {
        listener({ type: 'hilos_user_update_fail' })
      }
    },
    standingFrame(data: Record<string, unknown>): void {
      for (const listener of projectListeners) {
        listener({
          kind: 'project',
          type: 'hilos_account_standing_state',
          data,
        })
      }
    },
    answerMerge(reason?: string, reply?: unknown): void {
      const requestId = sent.at(-1)?.requestId
      const signal =
        reason === undefined
          ? {
              kind: 'actionSuccess',
              action: 'hilos_user_merge',
              requestId,
              ...(reply === undefined ? { message: 'Merged.' } : { reply }),
            }
          : {
              kind: 'actionError',
              action: 'hilos_user_merge',
              requestId,
              reason,
            }
      for (const listener of actionListeners.get(signal.kind) ?? []) {
        listener(signal)
      }
    },
    answerMergeCancel(
      reason?: string,
      requestId = sent
        .filter((entry) => entry.action === 'hilos_user_merge_cancel')
        .at(-1)?.requestId,
    ): void {
      const signal =
        reason === undefined
          ? {
              kind: 'actionSuccess',
              action: 'hilos_user_merge_cancel',
              requestId,
            }
          : {
              kind: 'actionError',
              action: 'hilos_user_merge_cancel',
              requestId,
              reason,
            }
      for (const listener of actionListeners.get(signal.kind) ?? [])
        listener(signal)
    },
    disconnect(): void {
      for (const listener of stateListeners) listener('disconnected')
    },
    updateCandidate(fields: Record<string, unknown>): void {
      Object.assign(candidateFields, fields)
      for (const listener of tableDeltaListeners)
        listener({
          data: {
            page: HilosPages.USER,
            tableKey: 'mergeCandidates',
            kind: 'row_updated',
            rowKey: '2',
            row: candidateRows()[0],
          },
        })
    },
    removeCandidate(): void {
      const signal = {
        data: {
          page: HilosPages.USER,
          tableKey: 'mergeCandidates',
          kind: 'row_removed',
          rowKey: '2',
          reason: 'deleted',
        },
      }
      for (const listener of tableDeltaListeners) {
        listener(signal)
      }
    },
    answerImpersonate(reason?: string): void {
      const requestId = sent
        .filter((entry) => entry.action === 'hilos_impersonate_start')
        .at(-1)?.requestId
      const signal =
        reason === undefined
          ? {
              kind: 'actionSuccess',
              action: 'hilos_impersonate_start',
              requestId,
            }
          : {
              kind: 'actionError',
              action: 'hilos_impersonate_start',
              requestId,
              reason,
            }
      for (const listener of actionListeners.get(signal.kind) ?? []) {
        listener(signal)
      }
    },
    sent,
  }
}

/**
 * A standing as the wire carries it.
 *
 * @param facts The facts that differ from a plain account.
 */
function standing(
  facts: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    shown: 'none',
    blocked: false,
    frozen: false,
    deletionEffectiveAt: null,
    deletionGraceDays: null,
    lapsed: [],
    window: [],
    mergedInto: null,
    mergedIntoName: null,
    ...facts,
  }
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function nameInput(): HTMLInputElement {
  return byId('hilos-user-name-input') as HTMLInputElement
}

function saveButton(): HTMLButtonElement {
  return byId('hilos-user-save') as HTMLButtonElement
}

function mergeConfirm(): HTMLButtonElement {
  return byId('hilos-user-merge-confirm') as HTMLButtonElement
}

/** Open the merge window on the rendered card and take Bob to its second step. */
async function openMergeOnBob(): Promise<void> {
  for (const id of [
    'hilos-user-merge-open',
    'hilos-user-merge-row-2',
    'hilos-user-merge-next',
  ]) {
    await act(async () => {
      fireEvent.click(byId(id) as Element)
    })
  }
}

/** Render the seeded page and open the rename modal. */
function openModal(context: HilosUsersContext): void {
  const { container } = renderPage(context)
  fireEvent.click(
    container.querySelector('[data-id="hilos-user-edit"]') as Element,
  )
}

function renderPage(context: HilosUsersContext, value: HilosRouter = router()) {
  return render(
    <HilosRouterContext.Provider value={value}>
      <HilosUserPage context={context} />
    </HilosRouterContext.Provider>,
  )
}

describe('HilosUserPage', () => {
  afterEach(() => {
    cleanup()
    for (const release of readerTimeZoneReleases.splice(0)) release()
    document.body.classList.remove('modal-open')
  })

  it('renders the loading state until the detail row lands', () => {
    const { container } = renderPage(userContext(false))
    expect(
      container.querySelector('[data-id="hilos-user-empty"]'),
    ).not.toBeNull()
    expect(container.querySelector('[data-id="hilos-user-detail"]')).toBeNull()
  })

  it('renders the user profile card from the detail row', () => {
    const { container } = renderPage(userContext(true))
    expect(
      container.querySelector('[data-id="hilos-user-name"]')?.textContent,
    ).toBe('Alice')
    expect(
      container.querySelector('[data-id="hilos-user-id"]')?.textContent,
    ).toBe('1')
    expect(
      container.querySelector('[data-id="hilos-user-sessions"]')?.textContent,
    ).toBe('2')
  })

  it('shows the read-only time zone as explicit, automatic, or hidden', async () => {
    const world = userContext(true)
    const page = world.scopes.page()!
    const { container } = renderPage(world)
    const row = byId('hilos-user-time-zone')!

    expect(row.previousElementSibling?.textContent).toBe('Time zone')
    expect(row.textContent).toBe('Automatic — follows the browser')

    await act(async () => {
      page.entities.upsert(
        { type: 'user', id: 1 },
        { timeZone: 'Pacific/Honolulu' },
      )
    })
    expect(row.textContent).toBe('Pacific/Honolulu')

    await act(async () => {
      page.entities.upsert({ type: 'user', id: 1 }, { timeZone: HIDDEN_VALUE })
    })
    expect(row.querySelector('[data-id="hilos-hidden"]')?.textContent).toBe(
      'Hidden',
    )
    expect(row.outerHTML).not.toContain('Pacific/Honolulu')
    expect(
      row.querySelectorAll('button, a, input, select, textarea'),
    ).toHaveLength(0)

    await act(async () => {
      page.entities.upsert({ type: 'user', id: 1 }, { timeZone: null })
    })
    expect(row.textContent).toBe('Automatic — follows the browser')
    expect(
      container.querySelector('[data-id="hilos-user-edit"]'),
    ).not.toBeNull()
  })

  it('reformats card and merge-candidate activity for the reader without resetting the open picker', async () => {
    const zone = readerTimeZone()
    readerTimeZoneReleases.push(zone.release)
    await act(async () => {
      zone.choose('Asia/Tokyo')
    })
    const world = userContext(true, true)
    const page = world.scopes.page()!
    page.entities.upsert({ type: 'user', id: 1 }, { lastActivity: 0 })
    page.entities.upsert({ type: 'user', id: 2 }, { lastActivity: 0 })
    renderPage(world)
    const before = formatMoment(0)

    expect(byId('hilos-user-last-activity')?.textContent).toBe(before)

    await act(async () => {
      fireEvent.click(byId('hilos-user-merge-open')!)
    })
    const search = byId('hilos-table-search') as HTMLInputElement
    await act(async () => {
      fireEvent.change(search, { target: { value: 'Bob' } })
      fireEvent.click(byId('hilos-user-merge-row-2')!)
    })
    const candidateRow = byId('hilos-table-row-2')
    const selected = byId('hilos-user-merge-row-2') as HTMLInputElement
    expect(candidateRow?.textContent).toContain(before)
    expect(selected.checked).toBe(true)

    await act(async () => {
      zone.choose('Pacific/Kiritimati')
    })
    const after = formatMoment(0)

    expect(after).not.toBe(before)
    expect(byId('hilos-user-last-activity')?.textContent).toBe(after)
    expect(byId('hilos-table-row-2')?.textContent).toContain(after)
    expect(byId('hilos-table-row-2')).toBe(candidateRow)
    expect(byId('hilos-table-search')).toBe(search)
    expect((byId('hilos-table-search') as HTMLInputElement).value).toBe('Bob')
    expect(byId('hilos-user-merge-row-2')).toBe(selected)
    expect((byId('hilos-user-merge-row-2') as HTMLInputElement).checked).toBe(
      true,
    )
    expect(byId('hilos-user-merge-cancel')).not.toBeNull()
  })

  it('shows the merge zone only when enabled and locks Next without a candidate', async () => {
    const disabled = renderPage(userContext(true))
    expect(
      disabled.container.querySelector('[data-id="hilos-user-merge-zone"]'),
    ).toBeNull()
    disabled.unmount()

    const enabled = renderPage(userContext(true, true))
    const open = enabled.container.querySelector(
      '[data-id="hilos-user-merge-open"]',
    ) as Element
    expect(open).not.toBeNull()
    await act(async () => {
      fireEvent.click(open)
    })
    expect((byId('hilos-user-merge-next') as HTMLButtonElement).disabled).toBe(
      true,
    )
  })

  it("keeps a refused merge open in the server's words, then says the candidate is gone", async () => {
    const context = userContext(true, true)
    renderPage(context)
    await act(async () => {
      fireEvent.click(byId('hilos-user-merge-open') as Element)
    })
    await act(async () => {
      fireEvent.click(byId('hilos-user-merge-row-2') as Element)
    })
    await act(async () => {
      fireEvent.click(byId('hilos-user-merge-next') as Element)
    })
    await act(async () => {
      fireEvent.click(byId('hilos-user-merge-confirm') as Element)
    })
    await act(async () => {
      context.answerMerge('Merge refused')
    })

    expect(byId('modal')).not.toBeNull()
    expect(document.body.textContent).toContain('Merge refused')

    await act(async () => {
      context.removeCandidate()
    })
    expect(byId('hilos-user-merge-gone')?.textContent).toContain(
      'No longer available',
    )
    expect(
      (byId('hilos-user-merge-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  // Merge is locked by its own merge in flight, which never turns it off
  // natively; a reason of its own does — a password fate not chosen yet, the
  // other account gone from the list.
  it('turns Merge off for a missing password fate, apart from its own flight', async () => {
    const context = userContext(true, true, 'skip', {
      detailHasPassword: true,
      candidateHasPassword: true,
    })
    renderPage(context)
    await openMergeOnBob()
    const merges = () =>
      context.sent.filter((entry) => entry.action === 'hilos_user_merge')

    expect(mergeConfirm().disabled).toBe(true)
    expect(mergeConfirm().getAttribute('aria-busy')).toBeNull()
    await act(async () => {
      fireEvent.click(mergeConfirm())
    })
    expect(merges()).toHaveLength(0)

    await act(async () => {
      fireEvent.click(byId('hilos-user-merge-fate-survivor') as Element)
    })
    expect(mergeConfirm().disabled).toBe(false)

    await act(async () => {
      fireEvent.click(mergeConfirm())
    })
    expect(merges()).toHaveLength(1)
    expect(mergeConfirm().disabled).toBe(false)
    expect(mergeConfirm().getAttribute('aria-busy')).toBe('true')
    expect(mergeConfirm().getAttribute('aria-disabled')).toBe('true')
    await act(async () => {
      fireEvent.click(mergeConfirm())
    })
    expect(merges()).toHaveLength(1)
  })

  it('turns Merge off when the other account goes while its merge flies', async () => {
    const context = userContext(true, true)
    renderPage(context)
    await openMergeOnBob()
    await act(async () => {
      fireEvent.click(mergeConfirm())
    })
    expect(mergeConfirm().disabled).toBe(false)
    expect(mergeConfirm().getAttribute('aria-busy')).toBe('true')

    await act(async () => {
      context.removeCandidate()
    })
    expect(byId('hilos-user-merge-gone')).not.toBeNull()
    expect(mergeConfirm().disabled).toBe(true)
    expect(mergeConfirm().getAttribute('aria-busy')).toBe('true')

    await act(async () => {
      context.answerMerge('Merge refused')
    })
    expect(byId('modal')).not.toBeNull()
    expect(mergeConfirm().disabled).toBe(true)
    expect(mergeConfirm().getAttribute('aria-busy')).toBeNull()
    await act(async () => {
      fireEvent.click(mergeConfirm())
    })
    expect(
      context.sent.filter((entry) => entry.action === 'hilos_user_merge'),
    ).toHaveLength(1)
  })

  it('opens the rename modal prefilled with the current name on Edit', () => {
    const { container } = renderPage(userContext(true))
    // Editing is modal, not inline: nothing is mounted until Edit, and the modal
    // portals to <body>, so the input is queried on the document, not container.
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-user-name-input"]'),
    ).toBeNull()
    fireEvent.click(
      container.querySelector('[data-id="hilos-user-edit"]') as Element,
    )
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    const input = document.querySelector(
      '[data-id="hilos-user-name-input"]',
    ) as HTMLInputElement
    expect(input).not.toBeNull()
    expect(input.value).toBe('Alice')
  })

  it('locks the rename modal until its save lands, keeps a refused draft, and closes on retry', () => {
    const context = userContext(true)
    openModal(context)
    fireEvent.change(nameInput(), { target: { value: 'Alicia' } })

    const input = nameInput()
    const form = input.form as HTMLFormElement
    const modal = byId('modal') as HTMLElement
    const fieldset = modal.querySelector(
      '.modal-body > fieldset',
    ) as HTMLFieldSetElement
    const close = byId('modal-close') as HTMLButtonElement
    const cancel = byId('hilos-user-cancel') as HTMLButtonElement

    fireEvent.submit(form)
    expect(
      context.sent.filter((entry) => entry.action === 'hilos_user_update'),
    ).toHaveLength(1)
    expect(fieldset.disabled).toBe(true)
    expect(input.matches(':disabled')).toBe(true)
    expect(cancel.matches(':disabled')).toBe(true)
    expect(close.disabled).toBe(true)

    fireEvent.submit(form)
    fireEvent.click(cancel)
    fireEvent.click(close)
    fireEvent.keyDown(modal, { key: 'Escape' })
    fireEvent.click(modal)
    expect(
      context.sent.filter((entry) => entry.action === 'hilos_user_update'),
    ).toHaveLength(1)
    expect(byId('modal')).toBe(modal)
    expect(nameInput()).toBe(input)
    expect(input.form).toBe(form)
    expect(input.value).toBe('Alicia')

    act(() => context.failRename())
    expect(fieldset.disabled).toBe(false)
    expect(input.matches(':disabled')).toBe(false)
    expect(cancel.matches(':disabled')).toBe(false)
    expect(input.value).toBe('Alicia')
    expect(byId('hilos-user-rename-error')?.textContent).toContain(
      'Could not rename the user. Please try again.',
    )

    fireEvent.submit(form)
    expect(
      context.sent.filter((entry) => entry.action === 'hilos_user_update'),
    ).toHaveLength(2)
    expect(fieldset.disabled).toBe(true)
    act(() => context.renameElsewhere('Alicia'))
    expect(byId('modal')).toBeNull()
  })

  // The rename modal on the shared row-edit helper (HIL-1050), under the same
  // case names as vue/src/admin/users/HilosUserPage.test.ts.
  it('opens on the committed name with save locked and the message line empty', () => {
    openModal(userContext(true))

    expect(nameInput().value).toBe('Alice')
    expect(saveButton().disabled).toBe(true)
    expect(byId('hilos-user-edit-notice')).toBeNull()
    expect(byId('hilos-user-edit-notice-idle')).not.toBeNull()
  })

  it('reloads a pristine edit when the name changes elsewhere and says so', () => {
    const context = userContext(true)
    openModal(context)

    act(() => {
      context.renameElsewhere('Alicia')
    })

    expect(nameInput().value).toBe('Alicia')
    expect(byId('conflict-badge')).toBeNull()
    expect(byId('hilos-user-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)

    // The person types over the taken name: the note about it goes out.
    fireEvent.change(nameInput(), { target: { value: 'Alicia B' } })
    expect(byId('hilos-user-edit-notice')).toBeNull()
    expect(saveButton().disabled).toBe(false)
  })

  it('sends nothing on Enter while a conflict stands', () => {
    const context = userContext(true)
    openModal(context)
    fireEvent.change(nameInput(), { target: { value: 'Mine' } })
    act(() => {
      context.renameElsewhere('Theirs')
    })

    fireEvent.submit(nameInput().form as HTMLFormElement)

    expect(context.sent).toHaveLength(0)
    expect(byId('conflict-badge')).not.toBeNull()
  })

  it('surfaces a conflict on a dirty edit, hides merge, and Keep mine sends mine', () => {
    const context = userContext(true)
    openModal(context)
    fireEvent.change(nameInput(), { target: { value: 'Mine' } })

    act(() => {
      context.renameElsewhere('Theirs')
    })

    expect(byId('conflict-badge')).not.toBeNull()
    expect(byId('hilos-user-edit-notice')?.textContent).toContain(
      'Changed elsewhere to "Theirs"',
    )
    expect(byId('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)
    expect(nameInput().value).toBe('Mine')

    fireEvent.click(byId('conflict-accept-mine') as Element)
    expect(byId('conflict-badge')).toBeNull()
    expect(byId('hilos-user-edit-notice')).toBeNull()
    expect(saveButton().disabled).toBe(false)

    fireEvent.click(saveButton())
    expect(context.sent).toHaveLength(1)
    expect(context.sent[0]?.action).toBe('hilos_user_update')
    expect(context.sent[0]?.data).toEqual({ id: 1, name: 'Mine' })
  })

  it('Take theirs sets the name to the live value, says so, and locks save', () => {
    const context = userContext(true)
    openModal(context)
    fireEvent.change(nameInput(), { target: { value: 'Mine' } })
    act(() => {
      context.renameElsewhere('Theirs')
    })

    fireEvent.click(byId('conflict-accept-theirs') as Element)

    expect(nameInput().value).toBe('Theirs')
    expect(byId('conflict-badge')).toBeNull()
    expect(byId('hilos-user-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', () => {
    const context = userContext(true)
    openModal(context)
    fireEvent.change(nameInput(), { target: { value: 'Mine' } })
    act(() => {
      context.removeRow()
    })

    expect(byId('hilos-user-edit-notice')?.textContent).toContain(
      'Deleted elsewhere',
    )
    expect(saveButton().disabled).toBe(true)
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(nameInput().value).toBe('Mine')
    expect(byId('modal')).not.toBeNull()
  })

  it('waits for the name it sent: Take theirs in flight keeps the modal open until that name lands', () => {
    const context = userContext(true)
    openModal(context)
    fireEvent.change(nameInput(), { target: { value: 'Mine' } })
    fireEvent.click(saveButton())
    expect(context.sent.at(-1)?.data).toEqual({ id: 1, name: 'Mine' })

    act(() => {
      context.renameElsewhere('Theirs')
    })
    fireEvent.click(byId('conflict-accept-theirs') as Element)
    // The draft now matches the live name, but that is not the name this rename sent.
    expect(nameInput().value).toBe('Theirs')
    expect(byId('modal')).not.toBeNull()

    act(() => {
      context.renameElsewhere('Other')
    })
    expect(nameInput().value).toBe('Other')
    expect(byId('modal')).not.toBeNull()

    act(() => {
      context.renameElsewhere('Mine')
    })
    expect(byId('modal')).toBeNull()
  })

  it('forgets the name it sent once the rename is refused', () => {
    const context = userContext(true)
    openModal(context)
    fireEvent.change(nameInput(), { target: { value: 'Mine' } })
    fireEvent.click(saveButton())

    act(() => {
      context.failRename()
    })
    expect(saveButton().disabled).toBe(false)

    act(() => {
      context.renameElsewhere('Mine')
    })
    expect(byId('modal')).not.toBeNull()
  })

  it('asks before discarding a changed draft', () => {
    openModal(userContext(true))
    fireEvent.change(nameInput(), { target: { value: 'Mine' } })

    fireEvent.click(byId('modal-close') as Element)
    // The discard question is the modal's own confirm step: the dialog stays.
    expect(byId('modal')).not.toBeNull()
    expect(byId('modal-confirm-discard')).not.toBeNull()

    fireEvent.click(byId('modal-confirm-discard') as Element)
    expect(byId('modal')).toBeNull()
  })
})

describe('HilosUserPage name hidden from a viewer of the admin view mode (HIL-1260)', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

  it('draws the mark and the person icon, and a rename window with the mark in place of the field', () => {
    const context = userContext(true)
    context.renameElsewhere({ _hidden: true } as unknown as string)
    openModal(context)

    expect(
      byId('hilos-user-name')
        ?.querySelector('[data-id="hilos-hidden"]')
        ?.textContent?.trim(),
    ).toBe('Hidden')
    expect(byId('hilos-avatar')?.querySelector('.bi-person')).not.toBeNull()
    expect(byId('modal')?.querySelector('.modal-title')?.textContent).toBe(
      'Rename · Hidden',
    )
    expect(
      document.querySelector('[data-id="hilos-user-name-input"]'),
    ).toBeNull()
    expect(
      byId('modal')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(saveButton().disabled).toBe(true)

    fireEvent.click(byId('hilos-user-cancel') as Element)
    expect(byId('modal-confirm-discard')).toBeNull()
    expect(byId('modal')).toBeNull()
  })
})

describe('HilosUserPage lifecycle', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })
  it('keeps own-account controls disabled with reasons and follows live rights', async () => {
    const context = userContext(true)
    renderPage(context)
    const find = byId
    const change = async (fn: () => void) => {
      await act(async () => {
        fn()
      })
    }

    await change(() => {
      context.scopes.session.data.set('currentUser', { type: 'user', id: 1 })
      context.scopes.page()?.data.set('accountDeletionGraceDays', 30)
      context.scopes
        .page()
        ?.entities.upsert({ type: 'user', id: 1 }, { admin: true })
    })
    expect(find('hilos-user-rights')).not.toBeNull()
    expect(find('hilos-user-access')).not.toBeNull()
    expect((find('hilos-user-admin-open') as HTMLButtonElement).disabled).toBe(
      true,
    )
    expect(find('hilos-user-admin-reason')?.textContent).toContain(
      'You cannot remove your own rights',
    )
    expect((find('hilos-user-block-open') as HTMLButtonElement).disabled).toBe(
      true,
    )
    expect(find('hilos-user-block-reason')?.textContent).toContain(
      'You cannot block yourself',
    )
    expect(
      (find('hilos-user-deletion-open') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(find('hilos-user-deletion-reason')?.textContent).toContain(
      'Delete your own account from your profile',
    )
    await change(() => {
      context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    })
    expect((find('hilos-user-admin-open') as HTMLButtonElement).disabled).toBe(
      false,
    )
    expect(find('hilos-user-deletion-reason')?.textContent).toContain(
      'Remove the admin rights first',
    )
    await change(() => {
      context.scopes
        .page()
        ?.entities.upsert({ type: 'user', id: 1 }, { admin: false })
    })
    expect(
      (find('hilos-user-deletion-open') as HTMLButtonElement).disabled,
    ).toBe(false)
  })

  it('names the deletion grace period and follows its live date and cancellation', async () => {
    const context = userContext(true)
    renderPage(context)
    const find = byId
    const change = async (fn: () => void) => {
      await act(async () => {
        fn()
      })
    }
    const click = async (id: string) => {
      await change(() => {
        fireEvent.click(find(id) as Element)
      })
    }
    expect(
      (find('hilos-user-deletion-open') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(find('hilos-user-deletion-reason')?.textContent?.trim()).toBe('')
    await change(() => {
      context.scopes.page()?.data.set('accountDeletionGraceDays', 30)
    })
    await click('hilos-user-deletion-open')
    expect(find('modal')?.textContent).toContain('After 30 days')
    await click('hilos-user-lifecycle-cancel')
    const effectiveAt = Date.now() + 30 * 86_400_000
    await change(() => {
      context.scopes.page()?.tables.upsert('userDetail', 1, {
        users: { type: 'user', id: 1 },
        accountDeletions: { deletionEffectiveAt: effectiveAt },
      })
    })
    expect(find('hilos-user-deletion-state')?.textContent).toContain(
      formatCalendarDate(effectiveAt),
    )
    expect(find('hilos-user-deletion-state')?.textContent).toContain(
      '30 days left',
    )
    expect(find('hilos-user-deletion-open')?.textContent).toContain(
      'Cancel deletion',
    )
    await change(() => {
      context.scopes.page()?.tables.upsert('userDetail', 1, {
        users: { type: 'user', id: 1 },
        accountDeletions: { deletionEffectiveAt: null },
      })
    })
    expect(find('hilos-user-deletion-open')?.textContent).toContain(
      'Delete account',
    )
  })

  it('waits for the tracked block reply, keeps a refusal in the modal and closes on success', async () => {
    const context = userContext(true)
    renderPage(context)
    const find = byId
    const change = async (fn: () => void) => {
      await act(async () => {
        fn()
      })
    }
    const click = async (id: string) => {
      await change(() => {
        fireEvent.click(find(id) as Element)
      })
    }
    let resolve!: (value: ActionResult) => void
    let reject!: (reason: unknown) => void
    await click('hilos-user-block-open')
    expect(find('modal')?.textContent).toContain('No reason is stored')
    const dispatch = vi
      .spyOn(context.actions, 'dispatch')
      .mockImplementation(() => ({
        requestId: 'lifecycle-test',
        loading: createSignal(false),
        done: new Promise<ActionResult>((yes, no) => {
          resolve = yes
          reject = no
        }),
      }))
    await click('hilos-user-lifecycle-confirm')
    expect(dispatch).toHaveBeenLastCalledWith('hilos_user_block_set', {
      userId: 1,
      block: true,
    })
    expect(
      find('hilos-user-lifecycle-confirm')?.getAttribute('aria-disabled'),
    ).toBe('true')
    expect(
      (find('hilos-user-lifecycle-cancel') as HTMLButtonElement).disabled,
    ).toBe(true)
    await change(() => {
      find('modal')?.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      )
    })
    expect(find('modal')).not.toBeNull()
    await change(() => {
      reject(
        new ActionError(
          'hilos_user_block_set',
          'fail',
          'No longer an administrator',
        ),
      )
    })
    expect(find('hilos-user-lifecycle-error')?.textContent).toContain(
      'No longer an administrator',
    )
    expect(find('modal')).not.toBeNull()
    await click('hilos-user-lifecycle-confirm')
    await change(() => {
      resolve({ message: 'Account blocked. Sessions ended: 1' })
    })
    expect(find('hilos-user-lifecycle-confirm')).toBeNull()
  })

  it('closes idle confirmation modals when background data invalidates the choice across all three pairs in both directions', async () => {
    const context = userContext(true)
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    context.scopes.page()?.data.set('accountDeletionGraceDays', 30)
    renderPage(context)
    const find = byId
    const change = async (fn: () => void) => {
      await act(async () => {
        fn()
      })
    }
    const click = async (id: string) => {
      await change(() => {
        fireEvent.click(find(id) as Element)
      })
    }
    const initialDispatchCount = vi.spyOn(context.actions, 'dispatch')

    // 1. Admin pair: grant -> admin becomes true
    let dispatches: number
    await click('hilos-user-admin-open')
    expect(find('modal')).not.toBeNull()
    dispatches = initialDispatchCount.mock.calls.length
    await change(() => {
      context.scopes
        .page()
        ?.entities.upsert({ type: 'user', id: 1 }, { admin: true })
    })
    expect(find('modal')).toBeNull()
    expect(initialDispatchCount.mock.calls.length).toBe(dispatches)
    expect(initialDispatchCount).not.toHaveBeenCalledWith(
      'hilos_user_admin_set',
      expect.anything(),
    )

    // 1b. Admin pair: revoke -> admin becomes false
    await click('hilos-user-admin-open')
    expect(find('modal')).not.toBeNull()
    dispatches = initialDispatchCount.mock.calls.length
    await change(() => {
      context.scopes
        .page()
        ?.entities.upsert({ type: 'user', id: 1 }, { admin: false })
    })
    expect(find('modal')).toBeNull()
    expect(initialDispatchCount.mock.calls.length).toBe(dispatches)
    expect(initialDispatchCount).not.toHaveBeenCalledWith(
      'hilos_user_admin_set',
      expect.anything(),
    )

    // 2. Block pair: block -> standing becomes blocked
    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()
    dispatches = initialDispatchCount.mock.calls.length
    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'blocked', blocked: true }),
      })
    })
    expect(find('modal')).toBeNull()
    expect(initialDispatchCount.mock.calls.length).toBe(dispatches)
    expect(initialDispatchCount).not.toHaveBeenCalledWith(
      'hilos_user_block_set',
      expect.anything(),
    )

    // 2b. Block pair: unblock -> standing becomes unblocked
    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()
    dispatches = initialDispatchCount.mock.calls.length
    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'none', blocked: false }),
      })
    })
    expect(find('modal')).toBeNull()
    expect(initialDispatchCount.mock.calls.length).toBe(dispatches)
    expect(initialDispatchCount).not.toHaveBeenCalledWith(
      'hilos_user_block_set',
      expect.anything(),
    )

    // 3. Deletion pair: delete -> deletion scheduled
    await click('hilos-user-deletion-open')
    expect(find('modal')).not.toBeNull()
    dispatches = initialDispatchCount.mock.calls.length
    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: standing({
          shown: 'deletion_scheduled',
          deletionEffectiveAt: 100_000_000,
        }),
      })
    })
    expect(find('modal')).toBeNull()
    expect(initialDispatchCount.mock.calls.length).toBe(dispatches)
    expect(initialDispatchCount).not.toHaveBeenCalledWith(
      'hilos_user_deletion_set',
      expect.anything(),
    )

    // 3b. Deletion pair: cancelDeletion -> deletion cleared
    await click('hilos-user-deletion-open')
    expect(find('modal')).not.toBeNull()
    dispatches = initialDispatchCount.mock.calls.length
    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: standing({
          shown: 'none',
          deletionEffectiveAt: null,
        }),
      })
    })
    expect(find('modal')).toBeNull()
    expect(initialDispatchCount.mock.calls.length).toBe(dispatches)
    expect(initialDispatchCount).not.toHaveBeenCalledWith(
      'hilos_user_deletion_set',
      expect.anything(),
    )

    // Independent update (rename) keeps modal open
    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()
    await change(() => {
      context.renameElsewhere('Bob')
    })
    expect(find('modal')).not.toBeNull()
    await click('hilos-user-lifecycle-cancel')
    expect(find('modal')).toBeNull()
  })

  it('closes the modal if choice is invalidated during delayed step-up opening', async () => {
    const context = userContext(true, false, 'ask')
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    renderPage(context)
    const find = byId
    const change = async (fn: () => void) => {
      await act(async () => {
        fn()
      })
    }
    const click = async (id: string) => {
      await change(() => {
        fireEvent.click(find(id) as Element)
      })
    }

    await click('hilos-user-block-open')
    expect(find('hilos-user-lifecycle-step-up')).not.toBeNull()

    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'blocked', blocked: true }),
      })
    })
    expect(find('modal')).toBeNull()
  })

  it('closes the modal when user row disappears', async () => {
    const context = userContext(true)
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    renderPage(context)
    const find = byId
    const change = async (fn: () => void) => {
      await act(async () => {
        fn()
      })
    }
    const click = async (id: string) => {
      await change(() => {
        fireEvent.click(find(id) as Element)
      })
    }

    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()

    await change(() => {
      context.removeRow()
    })
    expect(find('modal')).toBeNull()
  })

  it('does not dispatch an invalidated action if clicked between render and submit', async () => {
    const context = userContext(true)
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    renderPage(context)
    const find = byId
    const click = async (id: string) => {
      await act(async () => {
        fireEvent.click(find(id) as Element)
      })
    }

    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()

    const dispatch = vi.spyOn(context.actions, 'dispatch')
    await act(async () => {
      context.standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'blocked', blocked: true }),
      })
      fireEvent.click(find('hilos-user-lifecycle-confirm') as Element)
    })
    expect(dispatch).not.toHaveBeenCalled()
  })

  it('keeps modal open when standing changes while action is in flight, preserving readable refusal with disabled confirm', async () => {
    const context = userContext(true)
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    renderPage(context)
    const find = byId
    const change = async (fn: () => void) => {
      await act(async () => {
        fn()
      })
    }
    const click = async (id: string) => {
      await change(() => {
        fireEvent.click(find(id) as Element)
      })
    }
    let reject!: (reason: unknown) => void

    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()

    vi.spyOn(context.actions, 'dispatch').mockImplementation(() => ({
      requestId: 'test-req',
      loading: createSignal(false),
      done: new Promise<ActionResult>((_, no) => {
        reject = no
      }),
    }))

    await click('hilos-user-lifecycle-confirm')
    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'blocked', blocked: true }),
      })
    })
    expect(find('modal')).not.toBeNull()

    await change(() => {
      reject(
        new ActionError(
          'hilos_user_block_set',
          'fail',
          'Operation refused by server',
        ),
      )
    })

    expect(find('modal')).not.toBeNull()
    expect(find('hilos-user-lifecycle-error')?.textContent).toContain(
      'Operation refused by server',
    )
    expect(
      (find('hilos-user-lifecycle-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)

    await change(() => {
      context.renameElsewhere('Bob')
    })
    expect(find('modal')).not.toBeNull()
    expect(find('hilos-user-lifecycle-error')?.textContent).toContain(
      'Operation refused by server',
    )
    expect(
      (find('hilos-user-lifecycle-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)

    await click('hilos-user-lifecycle-cancel')
    expect(find('modal')).toBeNull()
  })
})

describe('HilosUserPage confirmation step (HIL-1275)', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

  async function click(id: string): Promise<void> {
    await act(async () => {
      fireEvent.click(byId(id) as Element)
    })
  }

  async function typePassword(text: string): Promise<void> {
    await act(async () => {
      fireEvent.change(byId('step-up-password') as Element, {
        target: { value: text },
      })
    })
  }

  it('asks by the merge operation and shows no other account before the step is passed', async () => {
    const context = userContext(true, true, 'ask')
    const register = vi.spyOn(context.connection, 'registerTableWindow')
    const candidatesAsked = () =>
      register.mock.calls.some(([tableKey]) => tableKey === 'mergeCandidates')
    renderPage(context)
    await click('hilos-user-merge-open')

    expect(context.sent[0]).toMatchObject({
      action: 'hilos_step_up_start',
      data: { operation: 'merge_accounts' },
    })
    expect(byId('modal')?.textContent).toContain("Confirm it's you")
    expect(byId('step-up-password')).not.toBeNull()
    expect(byId('hilos-user-merge-next')).toBeNull()
    expect(candidatesAsked()).toBe(false)

    await typePassword('secret')
    await click('hilos-user-merge-step-up-confirm')

    expect(
      context.sent.find((entry) => entry.action === 'hilos_step_up_confirm'),
    ).toMatchObject({
      data: { operation: 'merge_accounts', password: 'secret' },
    })
    expect(byId('step-up-password')).toBeNull()
    expect(byId('hilos-user-merge-next')).not.toBeNull()
    expect(candidatesAsked()).toBe(true)
  })

  it('opens the merge on its first step when no confirmation is needed', async () => {
    const context = userContext(true, true, 'skip')
    renderPage(context)
    await click('hilos-user-merge-open')

    expect(context.sent[0]?.action).toBe('hilos_step_up_start')
    expect(byId('step-up')).toBeNull()
    expect(byId('hilos-user-merge-next')).not.toBeNull()
  })

  it('draws a refusal with Cancel alone', async () => {
    renderPage(userContext(true, true, 'refused'))
    await click('hilos-user-merge-open')

    expect(byId('step-up-error')?.textContent).toContain(STEP_UP_REFUSAL)
    expect(byId('hilos-user-merge-cancel')).not.toBeNull()
    expect(byId('hilos-user-merge-step-up-confirm')).toBeNull()
    expect(byId('hilos-user-merge-next')).toBeNull()
  })

  it('asks before granting rights, then shows the confirmation text; Enter in the field confirms', async () => {
    const context = userContext(true, false, 'ask')
    renderPage(context)
    await click('hilos-user-admin-open')

    expect(context.sent[0]).toMatchObject({
      action: 'hilos_step_up_start',
      data: { operation: 'grant_admin' },
    })
    expect(byId('hilos-user-lifecycle-step-up-confirm')).not.toBeNull()
    expect(byId('hilos-user-lifecycle-confirm')).toBeNull()

    await typePassword('secret')
    await act(async () => {
      fireEvent.submit(byId('hilos-user-lifecycle-step-up') as Element)
    })

    expect(
      context.sent.find((entry) => entry.action === 'hilos_step_up_confirm'),
    ).toMatchObject({ data: { operation: 'grant_admin' } })
    expect(byId('step-up')).toBeNull()
    expect(byId('hilos-user-lifecycle-confirm')).not.toBeNull()
    expect(byId('modal')?.textContent).not.toContain("Confirm it's you")
  })

  it('opens Lift the block without asking the server', async () => {
    const context = userContext(true, false, 'ask')
    renderPage(context)
    await act(async () => {
      context.scopes
        .page()
        ?.entities.upsert({ type: 'user', id: 1 }, { block: true })
    })
    await click('hilos-user-block-open')

    expect(context.sent).toEqual([])
    expect(byId('step-up')).toBeNull()
    expect(byId('hilos-user-lifecycle-confirm')).not.toBeNull()
  })

  it('shows removal notices for unconfirmed password addresses in the merge window (HIL-1276)', async () => {
    // 1. Password on both accounts, candidate unconfirmed
    const loserUnverifiedContext = userContext(true, true, 'skip', {
      detailHasPassword: true,
      candidateHasPassword: true,
      candidateUnverifiedPasswordAddress: 'bob@example.test',
    })
    const { unmount: u1 } = renderPage(loserUnverifiedContext)
    await click('hilos-user-merge-open')
    await click('hilos-user-merge-row-2')
    await click('hilos-user-merge-next')

    const survivorRemoves = byId('hilos-user-merge-fate-survivor-removes')
    expect(survivorRemoves?.textContent).toContain(
      'bob@example.test is not confirmed and will be removed, not moved.',
    )
    const noneRemoves = byId('hilos-user-merge-fate-none-removes')
    expect(noneRemoves?.textContent).toContain(
      'bob@example.test is not confirmed and will be removed, not moved.',
    )
    expect(byId('hilos-user-merge-fate-loser-removes')).toBeNull()
    const survivorRadio = document.getElementById(
      'hilos-user-merge-fate-survivor-field',
    )
    expect(survivorRadio?.getAttribute('aria-describedby')).toBe(
      'hilos-user-merge-fate-survivor-removes',
    )
    u1()

    // 2. Password on both accounts, survivor unconfirmed
    const survivorUnverifiedContext = userContext(true, true, 'skip', {
      detailHasPassword: true,
      detailUnverifiedPasswordAddress: 'alice@example.test',
      candidateHasPassword: true,
    })
    const { unmount: u2 } = renderPage(survivorUnverifiedContext)
    await click('hilos-user-merge-open')
    await click('hilos-user-merge-row-2')
    await click('hilos-user-merge-next')

    expect(byId('hilos-user-merge-fate-survivor-removes')).toBeNull()
    const loserRemoves = byId('hilos-user-merge-fate-loser-removes')
    expect(loserRemoves?.textContent).toContain(
      'alice@example.test is not confirmed and will be removed.',
    )
    u2()

    // 3. Both passwords confirmed
    const confirmedContext = userContext(true, true, 'skip', {
      detailHasPassword: true,
      candidateHasPassword: true,
    })
    const { unmount: u3 } = renderPage(confirmedContext)
    await click('hilos-user-merge-open')
    await click('hilos-user-merge-row-2')
    await click('hilos-user-merge-next')

    expect(byId('hilos-user-merge-fate-survivor-removes')).toBeNull()
    expect(byId('hilos-user-merge-fate-loser-removes')).toBeNull()
    expect(byId('hilos-user-merge-fate-none-removes')).toBeNull()
    u3()

    // 4. Password only on one account
    const singlePasswordContext = userContext(true, true, 'skip', {
      detailHasPassword: true,
      candidateHasPassword: false,
    })
    const { unmount: u4 } = renderPage(singlePasswordContext)
    await click('hilos-user-merge-open')
    await click('hilos-user-merge-row-2')
    await click('hilos-user-merge-next')

    expect(byId('hilos-user-merge-fate-survivor')).toBeNull()
    expect(byId('hilos-user-merge-fate-survivor-removes')).toBeNull()
    u4()
  })
})

/** The installation's impersonation settings as the card's first answer carries them. */
function impersonationSettings(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    allowed: true,
    scope: 'act',
    carryAdmin: false,
    blocked: true,
    frozen: true,
    equal: true,
    ...overrides,
  }
}

describe('HilosUserPage impersonation (HIL-1170)', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
    hilosToasts.clear()
  })

  async function click(id: string): Promise<void> {
    await act(async () => {
      fireEvent.click(byId(id) as Element)
    })
  }

  /** Let a reply reach the tracked driver and the window. */
  async function settle(): Promise<void> {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0))
    })
  }

  it('draws no section while the card carries no settings or impersonation is off', () => {
    const { unmount } = renderPage(userContext(true))
    expect(byId('hilos-user-impersonate-open')).toBeNull()
    unmount()

    renderPage(
      userContext(true, false, 'skip', {
        impersonation: impersonationSettings({ allowed: false }),
      }),
    )
    expect(byId('hilos-user-impersonate-open')).toBeNull()
  })

  it('says what the takeover will be by the settings', () => {
    const { unmount } = renderPage(
      userContext(true, false, 'skip', {
        impersonation: impersonationSettings(),
      }),
    )
    const open = byId('hilos-user-impersonate-open') as HTMLButtonElement
    const section = open.closest('section')

    expect(section?.querySelector('h2')?.textContent).toBe('Impersonation')
    expect(section?.textContent).toContain('Act as Alice')
    expect(section?.textContent).toContain(
      'You will see the product through their eyes, without your admin rights',
    )
    expect(open.textContent?.trim()).toBe('Impersonate')
    expect(open.disabled).toBe(false)
    expect(byId('hilos-user-impersonate-reason')?.textContent).toBe('')
    unmount()

    renderPage(
      userContext(true, false, 'skip', {
        impersonation: impersonationSettings({ scope: 'view' }),
      }),
    )
    expect(
      byId('hilos-user-impersonate-open')?.closest('section')?.textContent,
    ).toContain('You will only look: nothing can be changed')
  })

  it('switches the button off with its reason on the own card and on an excluded administrator', () => {
    const context = userContext(true, false, 'skip', {
      impersonation: impersonationSettings({ equal: false }),
      currentUserId: 1,
    })
    renderPage(context)
    const open = () => byId('hilos-user-impersonate-open') as HTMLButtonElement

    expect(open().disabled).toBe(true)
    expect(open().getAttribute('aria-describedby')).toBe(
      'hilos-user-impersonate-reason',
    )
    expect(byId('hilos-user-impersonate-reason')?.textContent).toBe(
      'You cannot impersonate yourself',
    )

    act(() => {
      context.scopes.session.data.set('currentUser', {
        type: 'user',
        id: 99,
      })
      context.scopes
        .page()
        ?.entities.upsert({ type: 'user', id: 1 }, { admin: true })
    })

    expect(open().disabled).toBe(true)
    expect(byId('hilos-user-impersonate-reason')?.textContent).toBe(
      'Impersonating another administrator is switched off',
    )
  })

  it('opens the window after the server says no step is needed, and closes it on the takeover', async () => {
    const context = userContext(true, false, 'skip', {
      impersonation: impersonationSettings(),
    })
    renderPage(context)
    await click('hilos-user-impersonate-open')

    expect(context.sent[0]).toMatchObject({
      action: 'hilos_step_up_start',
      data: { operation: 'impersonate' },
    })
    const modal = byId('modal')
    expect(modal?.textContent).toContain('Impersonate · Alice')
    expect(modal?.textContent).toContain(
      'You will see the product through their eyes, without your admin rights.',
    )
    expect(modal?.textContent).toContain(
      'Everything you do in their name is written to the journal as done by you.',
    )

    await click('hilos-user-impersonate-confirm')
    expect(
      context.sent.find((entry) => entry.action === 'hilos_impersonate_start'),
    ).toMatchObject({ data: { targetUserId: 1 } })
    expect(
      byId('hilos-user-impersonate-confirm')?.getAttribute('aria-disabled'),
    ).toBe('true')

    act(() => context.answerImpersonate())
    await settle()

    expect(byId('modal')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('keeps a refusal in the window and toasts it', async () => {
    const context = userContext(true, false, 'skip', {
      impersonation: impersonationSettings(),
    })
    renderPage(context)
    await click('hilos-user-impersonate-open')
    await click('hilos-user-impersonate-confirm')

    act(() =>
      context.answerImpersonate(
        'Impersonating a blocked person is switched off',
      ),
    )
    await settle()

    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-user-impersonate-error')?.textContent).toContain(
      'Impersonating a blocked person is switched off',
    )
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'Impersonating a blocked person is switched off']])

    await click('hilos-user-impersonate-cancel')
    expect(byId('modal')).toBeNull()
  })

  it('asks for the confirmation step first when the operation is on', async () => {
    const context = userContext(true, false, 'ask', {
      impersonation: impersonationSettings(),
    })
    renderPage(context)
    await click('hilos-user-impersonate-open')

    expect(byId('hilos-user-impersonate-step-up')).not.toBeNull()
    expect(byId('hilos-user-impersonate-confirm')).toBeNull()

    await act(async () => {
      fireEvent.change(byId('step-up-password') as Element, {
        target: { value: 'secret' },
      })
    })
    await click('hilos-user-impersonate-step-up-confirm')

    expect(
      context.sent.find((entry) => entry.action === 'hilos_step_up_confirm'),
    ).toMatchObject({ data: { operation: 'impersonate', password: 'secret' } })
    expect(byId('hilos-user-impersonate-step-up')).toBeNull()
    expect(byId('hilos-user-impersonate-confirm')).not.toBeNull()
  })

  it('updates an open confirmation and closes it when impersonation becomes unavailable', async () => {
    const context = userContext(true, false, 'skip', {
      impersonation: impersonationSettings(),
    })
    renderPage(context)
    await click('hilos-user-impersonate-open')
    expect(byId('modal')).not.toBeNull()

    // Changing allowed to false via live session policy closes the window and hides the section.
    act(() => {
      context.scopes.session.data.set('impersonationPolicy', {
        viewOnly: false,
        carryAdmin: false,
        allowed: false,
        accountAccess: false,
        blocked: true,
        frozen: true,
        equal: true,
      })
    })
    await settle()

    expect(byId('modal')).toBeNull()
    expect(byId('hilos-user-impersonate-open')).toBeNull()

    // Bringing allowed back to true restores the section.
    act(() => {
      context.scopes.session.data.set('impersonationPolicy', {
        viewOnly: false,
        carryAdmin: false,
        allowed: true,
        accountAccess: false,
        blocked: true,
        frozen: true,
        equal: true,
      })
    })
    await settle()
    expect(byId('hilos-user-impersonate-open')).not.toBeNull()

    // Open the window again; updating the policy to equal=false when target user is an admin closes the window.
    await click('hilos-user-impersonate-open')
    expect(byId('modal')).not.toBeNull()

    act(() => {
      context.scopes.session.data.set('impersonationPolicy', {
        viewOnly: true,
        carryAdmin: false,
        allowed: true,
        accountAccess: false,
        blocked: true,
        frozen: true,
        equal: true,
      })
    })
    await settle()
    expect(byId('modal')?.textContent).toContain(
      'You will only look: nothing can be changed',
    )

    act(() => {
      context.scopes
        .page()
        ?.entities.upsert({ type: 'user', id: 1 }, { admin: true })
      context.scopes.session.data.set('impersonationPolicy', {
        viewOnly: false,
        carryAdmin: false,
        allowed: true,
        accountAccess: false,
        blocked: true,
        frozen: true,
        equal: false,
      })
    })
    await settle()

    expect(byId('modal')).toBeNull()
    const open = byId('hilos-user-impersonate-open') as HTMLButtonElement
    expect(open.disabled).toBe(true)
  })
})

describe('HilosUserPage standing (HIL-945)', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

  const change = async (fn: () => void) => {
    await act(async () => {
      fn()
    })
  }

  it('shows one badge for the standing shown and the freeze as a row without a control', async () => {
    const context = userContext(true)
    renderPage(context)
    const badge = () => byId('user-standing-badge')

    // No verdict yet: no badge and no freeze row.
    expect(badge()).toBeNull()
    expect(byId('hilos-user-frozen-state')).toBeNull()

    await change(() => {
      context.scopes.page()?.data.set(
        'accountStanding',
        standing({
          shown: 'frozen',
          frozen: true,
          lapsed: [{ document: 'terms', deadline: '2026-09-01' }],
        }),
      )
    })
    expect(badge()?.textContent?.trim()).toBe('Frozen')
    expect(badge()?.classList.contains('text-bg-info')).toBe(true)
    expect(badge()?.querySelector('.bi-snow')).not.toBeNull()
    expect(byId('hilos-user-frozen-state')?.textContent).toContain('Yes')
    expect(byId('hilos-user-frozen-lapsed')?.textContent).toContain(
      'Terms of use — deadline passed 1 September 2026',
    )
    expect(byId('hilos-user-frozen-open')).toBeNull()
    const states = Array.from(
      document.querySelectorAll(
        '[data-id="hilos-user-access"] [data-id$="-state"]',
      ),
    ).map((node) => node.getAttribute('data-id'))
    expect(states).toEqual([
      'hilos-user-block-state',
      'hilos-user-frozen-state',
      'hilos-user-deletion-state',
    ])

    // A live frame about the person moves the badge and the rows together.
    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'blocked', blocked: true }),
      })
    })
    expect(badge()?.textContent?.trim()).toBe('Blocked')
    expect(badge()?.classList.contains('text-bg-danger')).toBe(true)
    expect(byId('hilos-user-block-state')?.textContent).toContain('Yes')
    expect(byId('hilos-user-block-open')?.textContent).toContain(
      'Lift the block',
    )
    expect(byId('hilos-user-frozen-state')?.textContent).toContain('Not frozen')

    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: standing({
          lapsed: [{ document: 'privacy', deadline: '2026-09-15' }],
        }),
      })
    })
    expect(badge()).toBeNull()
    expect(byId('hilos-user-frozen-state')?.textContent).toContain(
      'Past deadline — reminders only',
    )
    expect(byId('hilos-user-frozen-lapsed')?.textContent).toContain(
      'Privacy policy — deadline passed 15 September 2026',
    )

    // Hidden from a viewer of the admin view mode: no standing to show.
    await change(() => {
      context.standingFrame({
        userId: { _hidden: true },
        accountStanding: { _hidden: true },
      })
    })
    expect(badge()).toBeNull()
    expect(byId('hilos-user-frozen-state')).not.toBeNull()
    await change(() => {
      context.standingFrame({ userId: 1, accountStanding: { _hidden: true } })
    })
    expect(byId('hilos-user-frozen-state')).toBeNull()
  })

  it('reads a scheduled deletion from the verdict', async () => {
    const context = userContext(true)
    renderPage(context)
    const effectiveAt = Date.now() + 5 * 86_400_000

    await change(() => {
      context.scopes.page()?.data.set(
        'accountStanding',
        standing({
          shown: 'deletion_scheduled',
          deletionEffectiveAt: effectiveAt,
        }),
      )
    })

    expect(byId('user-standing-badge')?.textContent?.trim()).toBe(
      'Deletion scheduled',
    )
    expect(
      byId('user-standing-badge')?.classList.contains('text-bg-warning'),
    ).toBe(true)
    expect(byId('hilos-user-deletion-state')?.textContent).toContain(
      '5 days left',
    )
    expect(byId('hilos-user-deletion-open')?.textContent).toContain(
      'Cancel deletion',
    )
  })
})

describe('HilosUserPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
    cleanup()
    document.body.classList.remove('modal-open')
  })

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

  async function click(id: string): Promise<void> {
    await act(async () => {
      fireEvent.click(byId(id) as Element)
    })
  }

  it.each([false, true])(
    'bounds the live deletion countdown from fallback and standing (viewer=%s)',
    async (viewer) => {
      vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
      const requestedAt = 1_800_000_000_000
      const day = 86_400_000
      const effectiveAt = requestedAt + 30 * day
      vi.setSystemTime(requestedAt)
      applyServerTime(requestedAt - 300)
      const world = userContext(false)
      const { scopes } = world
      bindSession(scopes).handshake(
        viewer ? null : { id: 99, admin: true },
        true,
      )
      const page = scopes.page()!
      page.data.set('accountDeletionGraceDays', viewer ? HIDDEN_VALUE : 7)
      page.tables.upsert('userDetail', 1, {
        users: { type: 'user', id: 1 },
        accountDeletions: {
          deletionEffectiveAt: effectiveAt,
          deletionGraceDays: 30,
        },
      })
      const wrapper = renderPage(world)
      const text = () => byId('hilos-user-deletion-state')?.textContent
      try {
        expect(text()).toContain(formatCalendarDate(effectiveAt))
        expect(text()).toContain('30 days left')

        act(() => {
          page.data.set(
            'accountStanding',
            standing({
              shown: 'deletion_scheduled',
              deletionEffectiveAt: effectiveAt,
              deletionGraceDays: 30,
            }),
          )
        })
        expect(text()).toContain('30 days left')

        vi.setSystemTime(requestedAt + day)
        await act(async () => {
          await vi.advanceTimersByTimeAsync(60_000)
        })
        expect(text()).toContain('29 days left')

        const rescheduledAt = Date.now() + 5 * day
        act(() => {
          world.standingFrame({
            userId: 1,
            accountStanding: standing({
              shown: 'deletion_scheduled',
              deletionEffectiveAt: rescheduledAt,
              deletionGraceDays: 5,
            }),
          })
        })
        expect(text()).toContain(formatCalendarDate(rescheduledAt))
        expect(text()).toContain('5 days left')

        if (viewer) {
          expect(scopes.pageDataSignal('accountDeletionGraceDays').get()).toBe(
            HIDDEN_VALUE,
          )
          await click('hilos-user-deletion-open')
          const confirm = byId(
            'hilos-user-lifecycle-confirm',
          ) as HTMLButtonElement
          expect(confirm.disabled).toBe(true)
          expect(confirm.getAttribute('aria-describedby')).toContain(
            HILOS_VIEW_MODE_STRIP_TEXT_ID,
          )
          await click('hilos-user-lifecycle-confirm')
        }
        expect(world.sent).toEqual([])
      } finally {
        wrapper.unmount()
        applyServerTime(Date.now())
        vi.useRealTimers()
      }
    },
  )

  it('a guest opens the merge dialog directly, chooses a candidate with hidden fields, and confirm is disabled', async () => {
    const context = userContext(true, true, 'skip', {
      candidateNameHidden: true,
      candidateIdentitiesHidden: true,
    })
    bindSession(context.scopes).handshake(null, true)
    renderPage(context)

    const openBtn = byId('hilos-user-merge-open') as HTMLButtonElement
    expect(openBtn).not.toBeNull()
    expect(openBtn.disabled).toBe(false)

    await click('hilos-user-merge-open')

    expect(byId('hilos-user-merge-step-up')).toBeNull()
    expect(context.sent).toHaveLength(0)

    const rowRadio = byId('hilos-user-merge-row-2') as HTMLInputElement
    expect(rowRadio).not.toBeNull()
    expect(rowRadio.getAttribute('aria-label')).toBe('Merge #2')
    const row2 = rowRadio.closest('tr')
    expect(row2?.querySelectorAll('[data-id="hilos-hidden"]')).toHaveLength(2)

    await click('hilos-user-merge-row-2')

    const nextBtn = byId('hilos-user-merge-next') as HTMLButtonElement
    expect(nextBtn).not.toBeNull()
    await click('hilos-user-merge-next')

    const summary = byId('hilos-user-merge-summary')
    expect(summary?.textContent).toContain('Hidden')

    const confirmBtn = byId('hilos-user-merge-confirm') as HTMLButtonElement
    expect(confirmBtn).not.toBeNull()
    expect(confirmBtn.disabled).toBe(true)
    expect(confirmBtn.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    await click('hilos-user-merge-confirm')
    expect(context.sent).toHaveLength(0)

    await click('hilos-user-merge-cancel')
    expect(byId('modal-confirm-discard')).not.toBeNull()
  })

  it('a guest opens admin, block and deletion lifecycle dialogs directly and has nothing to send', async () => {
    const context = userContext(true)
    context.scopes.page()?.data.set('accountDeletionGraceDays', HIDDEN_VALUE)
    bindSession(context.scopes).handshake(null, true)
    renderPage(context)

    for (const key of ['admin', 'block', 'deletion'] as const) {
      const openBtn = byId(`hilos-user-${key}-open`) as HTMLButtonElement
      expect(openBtn).not.toBeNull()
      expect(openBtn.disabled).toBe(false)

      await click(`hilos-user-${key}-open`)

      expect(byId('hilos-user-lifecycle-step-up')).toBeNull()
      const confirmBtn = byId(
        'hilos-user-lifecycle-confirm',
      ) as HTMLButtonElement
      expect(confirmBtn).not.toBeNull()
      expect(confirmBtn.disabled).toBe(true)
      expect(confirmBtn.getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )

      await click('hilos-user-lifecycle-confirm')
      expect(context.sent).toHaveLength(0)

      await click('hilos-user-lifecycle-cancel')
    }
  })

  it('a guest opens the takeover window directly and its confirm is disabled by the mode (HIL-1170)', async () => {
    const context = userContext(true, false, 'skip', {
      impersonation: impersonationSettings(),
    })
    bindSession(context.scopes).handshake(null, true)
    renderPage(context)

    const openBtn = byId('hilos-user-impersonate-open') as HTMLButtonElement
    expect(openBtn.disabled).toBe(false)
    await click('hilos-user-impersonate-open')

    expect(byId('hilos-user-impersonate-step-up')).toBeNull()
    const confirmBtn = byId(
      'hilos-user-impersonate-confirm',
    ) as HTMLButtonElement
    expect(confirmBtn.disabled).toBe(true)
    expect(confirmBtn.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    await click('hilos-user-impersonate-confirm')
    expect(context.sent).toHaveLength(0)
  })

  it('a logged-in non-admin on their own card sees block disabled by ownBlockReason without strip text id', () => {
    const context = userContext(true, false, 'skip', { currentUserId: 1 })
    bindSession(context.scopes).handshake({ id: 1, admin: false }, true)
    renderPage(context)

    const blockBtn = byId('hilos-user-block-open') as HTMLButtonElement
    expect(blockBtn).not.toBeNull()
    expect(blockBtn.disabled).toBe(true)
    expect(blockBtn.getAttribute('aria-describedby')).toBe(
      'hilos-user-block-reason',
    )
    expect(blockBtn.getAttribute('aria-describedby')).not.toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
  })

  it('an admin on a node in view mode retains full access and sends step-up start', async () => {
    const context = userContext(true, false, 'skip')
    bindSession(context.scopes).handshake({ id: 99, admin: true }, true)
    renderPage(context)

    const adminBtn = byId('hilos-user-admin-open') as HTMLButtonElement
    expect(adminBtn).not.toBeNull()
    expect(adminBtn.disabled).toBe(false)

    await click('hilos-user-admin-open')

    expect(context.sent[0]?.action).toBe('hilos_step_up_start')
  })
})

describe('HilosUserPage merged account (HIL-1292)', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

  /**
   * The standing of an account folded into another one, as the wire carries it.
   *
   * @param facts The facts that differ from a merge into Bob (#9).
   */
  function merged(
    facts: Record<string, unknown> = {},
  ): Record<string, unknown> {
    return {
      shown: 'merged',
      blocked: true,
      frozen: false,
      deletionEffectiveAt: null,
      lapsed: [],
      window: [],
      mergedInto: 9,
      mergedIntoName: 'Bob',
      ...facts,
    }
  }

  const change = async (fn: () => void) => {
    await act(async () => {
      fn()
    })
  }

  it('says where the account went and offers nothing to press', async () => {
    const context = userContext(true, true, 'skip', {
      impersonation: impersonationSettings(),
    })
    renderPage(context)
    expect(byId('hilos-user-edit')).not.toBeNull()
    expect(byId('hilos-user-access')).not.toBeNull()
    expect(byId('hilos-user-merge-zone')).not.toBeNull()
    expect(byId('hilos-user-impersonate-open')).not.toBeNull()

    // The merge lands under the open card: the badge turns, the notice names the
    // survivor, and every control goes - the server refuses them all.
    await change(() => {
      context.standingFrame({ userId: 1, accountStanding: merged() })
    })
    const badge = byId('user-standing-badge')
    expect(badge?.textContent?.trim()).toBe('Merged')
    expect(badge?.classList.contains('text-bg-secondary')).toBe(true)
    expect(badge?.querySelector('.bi-sign-merge-left')).not.toBeNull()
    const notice = byId('hilos-user-merged')
    expect(notice?.textContent).toContain('This account was merged into')
    expect(notice?.textContent).toContain('Bob')
    expect(notice?.textContent).toContain('(#9)')
    const link = byId('hilos-user-merged-link')
    expect(link?.getAttribute('href')).toBe('/hilos/user/9')
    expect(link?.textContent).toBe('Open card')
    expect(byId('hilos-user-edit')).toBeNull()
    expect(byId('hilos-user-rights')).toBeNull()
    expect(byId('hilos-user-access')).toBeNull()
    expect(byId('hilos-user-merge-zone')).toBeNull()
    expect(byId('hilos-user-impersonate-open')).toBeNull()
    expect(byId('hilos-user-sessions')).not.toBeNull()

    // A viewer of the admin view mode keeps the number and the way, not the name.
    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: merged({ mergedIntoName: { _hidden: true } }),
      })
    })
    expect(
      byId('hilos-user-merged')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(byId('hilos-user-merged')?.textContent).toContain('(#9)')
    expect(byId('hilos-user-merged-link')).not.toBeNull()

    // A chain that leads nowhere: merged, with nobody to point at.
    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: merged({ mergedInto: null, mergedIntoName: null }),
      })
    })
    expect(byId('hilos-user-merged')?.textContent?.trim()).toBe(
      'This account was merged into another one.',
    )
    expect(byId('hilos-user-merged-link')).toBeNull()
  })

  it('closes an open window when the merge lands', async () => {
    const context = userContext(true)
    openModal(context)
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()

    await change(() => {
      context.standingFrame({ userId: 1, accountStanding: merged() })
    })

    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(byId('hilos-user-merged')).not.toBeNull()
  })
})

describe('HilosUserPage link to the person in Analytics (HIL-1420)', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

  const change = async (fn: () => void) => {
    await act(async () => {
      fn()
    })
  }

  /**
   * Render the card over a router that knows the framework addresses.
   *
   * @param context The card's project context.
   * @param overrides Router members the test replaces.
   */
  function renderCard(
    context: HilosUsersContext,
    overrides: Partial<HilosRouter> = {},
  ): void {
    renderPage(context, router({ resolvePath: resolveHilosPath, ...overrides }))
  }

  it('draws no link before the card says Analytics is served or while it is off', async () => {
    const context = userContext(true)
    renderCard(context)
    expect(byId('hilos-user-detail')).not.toBeNull()
    expect(byId('hilos-user-analytics-link')).toBeNull()

    await change(() => {
      context.scopes.page()?.data.set('analyticsEnabled', false)
    })
    expect(byId('hilos-user-analytics-link')).toBeNull()
  })

  it('opens the person in Analytics from beside the card header', async () => {
    const context = userContext(true)
    context.scopes.page()?.data.set('analyticsEnabled', true)
    const navigate = vi.fn()
    renderCard(context, { navigate })

    const link = byId('hilos-user-analytics-link')
    expect(link?.tagName).toBe('A')
    expect(link?.textContent?.trim()).toBe('Actions in Analytics')
    expect(link?.getAttribute('href')).toBe('/hilos/analytics/person/1')
    expect(link?.closest('.card-header')).not.toBeNull()
    await change(() => link?.click())
    expect(navigate).toHaveBeenCalledWith('/hilos/analytics/person/1')
    expect(document.querySelector('[data-id="modal"]')).toBeNull()

    await change(() => {
      context.scopes.page()?.data.set('analyticsEnabled', false)
    })
    expect(byId('hilos-user-analytics-link')).toBeNull()
  })

  it('draws no link when the application has no address for the page', () => {
    const context = userContext(true)
    context.scopes.page()?.data.set('analyticsEnabled', true)
    renderCard(context, { resolvePath: () => undefined })
    expect(byId('hilos-user-detail')).not.toBeNull()
    expect(byId('hilos-user-analytics-link')).toBeNull()
  })

  it('keeps the link on a merged card that offers nothing to press', async () => {
    const context = userContext(true, true, 'skip', {
      impersonation: impersonationSettings(),
    })
    context.scopes.page()?.data.set('analyticsEnabled', true)
    renderCard(context)
    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: {
          shown: 'merged',
          blocked: true,
          frozen: false,
          deletionEffectiveAt: null,
          lapsed: [],
          window: [],
          mergedInto: 9,
          mergedIntoName: 'Bob',
        },
      })
    })

    expect(byId('hilos-user-merged')).not.toBeNull()
    expect(byId('hilos-user-edit')).toBeNull()
    expect(byId('hilos-user-access')).toBeNull()
    expect(byId('hilos-user-merge-zone')).toBeNull()
    expect(byId('hilos-user-impersonate-open')).toBeNull()
    expect(byId('hilos-user-analytics-link')?.getAttribute('href')).toBe(
      '/hilos/analytics/person/1',
    )
  })
})

describe('HilosUserPage presence (HIL-1566)', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

  const change = async (fn: () => void) => {
    await act(async () => {
      fn()
    })
  }

  /**
   * The header's presence: the room the twin holds, and the badge drawn over it.
   */
  function presence(): {
    room: HTMLElement | null
    twin: HTMLElement | null
    badge: HTMLElement | null
  } {
    const room = document.querySelector<HTMLElement>(
      '[data-id="hilos-user-detail"] .card-header .hilos-stack.d-inline-grid',
    )

    return {
      room,
      twin: room?.querySelector<HTMLElement>('.badge.invisible') ?? null,
      badge: room?.querySelector<HTMLElement>('.badge:not(.invisible)') ?? null,
    }
  }

  it('says the list word in the header from the first answer through every live change', async () => {
    const context = userContext(true)
    renderPage(context)
    const connections = (value: string, onlineSessionCount: number) => {
      context.scopes.page()?.tables.upsert('userDetail', 1, {
        users: { type: 'user', id: 1 },
        connections: { presence: value, onlineSessionCount },
      })
    }
    const sessions = () => byId('hilos-user-sessions')?.textContent?.trim()

    expect(presence().badge?.textContent?.trim()).toBe('online')
    expect(presence().badge?.classList.contains('text-bg-success')).toBe(true)
    expect(presence().twin?.textContent?.trim()).toBe('probably offline')
    expect(presence().twin?.getAttribute('aria-hidden')).toBe('true')
    // The twin is the only other badge in the card, and it is not seen.
    expect(
      Array.from(
        document.querySelectorAll('[data-id="hilos-user-detail"] .badge'),
      ).filter((badge) => !badge.classList.contains('invisible')),
    ).toEqual([presence().badge])

    await change(() => connections('probably_offline', 1))
    expect(presence().badge?.textContent?.trim()).toBe('probably offline')
    expect(presence().badge?.classList.contains('text-bg-warning')).toBe(true)
    expect(presence().badge?.classList.contains('text-bg-secondary')).toBe(
      false,
    )
    expect(sessions()).toBe('1')
    expect(document.body.textContent).not.toContain('probably_offline')

    // The standing badge stands beside it and does not change it.
    await change(() => {
      context.scopes
        .page()
        ?.data.set(
          'accountStanding',
          standing({ shown: 'blocked', blocked: true }),
        )
    })
    expect(byId('user-standing-badge')?.textContent?.trim()).toBe('Blocked')
    expect(presence().badge?.textContent?.trim()).toBe('probably offline')
    await change(() => {
      context.standingFrame({ userId: 1, accountStanding: standing({}) })
    })

    await change(() => connections('online', 2))
    expect(presence().badge?.textContent?.trim()).toBe('online')
    expect(presence().badge?.classList.contains('text-bg-success')).toBe(true)
    expect(sessions()).toBe('2')

    await change(() => connections('offline', 0))
    expect(presence().badge?.textContent?.trim()).toBe('offline')
    expect(presence().badge?.classList.contains('text-bg-secondary')).toBe(true)
    expect(presence().twin?.textContent?.trim()).toBe('probably offline')
    expect(sessions()).toBe('0')
  })
})

describe('HilosUserPage erasure (HIL-1961)', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
    hilosToasts.clear()
  })

  const startedAt = Date.parse('2026-01-01T00:30:05Z')
  const erasing = standing({ shown: 'erasing' })

  /**
   * The progress of a begun erasure as the wire carries it: the card's own
   * part and that of an account merged into it (#74), one owner awaited.
   *
   * @param facts The facts that differ from that progress.
   */
  function erasure(
    facts: Record<string, unknown> = {},
  ): Record<string, unknown> {
    return {
      startedAt,
      parts: [
        { participant: 'sessions', owner: 'auth', userId: 1, done: true },
        { participant: 'messages', owner: 'chat', userId: 74, done: false },
      ],
      waits: [{ participant: 'messages', userId: 74 }],
      refusals: [],
      ...facts,
    }
  }

  function lines(id: string): string[] {
    return Array.from(document.querySelectorAll(`[data-id="${id}"]`)).map(
      (line) => (line.textContent ?? '').replace(/\s+/g, ' ').trim(),
    )
  }

  it('draws Being deleted, the notice and the progress section when erasing with an accountErasure block', async () => {
    const context = userContext(true, true, 'skip', {
      impersonation: impersonationSettings(),
    })
    renderPage(context)
    const change = async (fn: () => void) => {
      await act(async () => {
        fn()
      })
    }

    expect(byId('hilos-user-edit')).not.toBeNull()
    expect(byId('hilos-user-erasure')).toBeNull()

    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: erasing,
        accountErasure: erasure(),
      })
    })

    expect(byId('user-standing-badge')?.textContent?.trim()).toBe(
      'Being deleted',
    )
    expect(byId('hilos-user-erasing')?.textContent?.trim()).toBe(
      'This account is being deleted.',
    )
    expect(byId('hilos-user-edit')).toBeNull()
    expect(byId('hilos-user-rights')).toBeNull()
    expect(byId('hilos-user-access')).toBeNull()
    expect(byId('hilos-user-merge-zone')).toBeNull()
    expect(byId('hilos-user-impersonate-open')).toBeNull()
    expect(byId('hilos-user-sessions')).not.toBeNull()

    const section = byId('hilos-user-erasure')!
    expect(section.querySelector('h2')?.textContent).toBe('Account erasure')
    expect(section.textContent).toContain('Erasure in progress')
    expect(
      Array.from(section.querySelectorAll('h3')).map((h) => h.textContent),
    ).toEqual(['Parts', 'Waiting for an owner'])
    expect(byId('hilos-user-erasure-started')?.textContent?.trim()).toBe(
      `Started ${String(formatMoment(startedAt, { kind: 'dateTime' }))} — it cannot be canceled`,
    )
    expect(lines('hilos-user-erasure-part')).toEqual([
      'sessions — Done',
      'messages · #74 — Expected',
    ])
    expect(lines('hilos-user-erasure-wait')).toEqual(['messages · #74'])
    expect(lines('hilos-user-erasure-refusal')).toEqual([])
    expect(section.querySelectorAll('button')).toHaveLength(0)
  })

  it('says the refused finals with the table and without it', async () => {
    const context = userContext(true)
    renderPage(context)
    const change = async (fn: () => void) => {
      await act(async () => {
        fn()
      })
    }

    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: erasing,
        accountErasure: erasure({
          waits: [],
          refusals: [
            { userId: 1, table: 'chat_message' },
            { userId: 74, table: null },
          ],
        }),
      })
    })

    expect(
      Array.from(byId('hilos-user-erasure')!.querySelectorAll('h3')).map(
        (h) => h.textContent,
      ),
    ).toEqual(['Parts', 'Refused'])
    expect(lines('hilos-user-erasure-refusal')).toEqual([
      'Table chat_message still references the account',
      '#74 · Another table still references the account',
    ])
    expect(lines('hilos-user-erasure-part')).toContain(
      'messages · #74 — Expected',
    )
  })

  it('closes an idle window when the erasure begins and keeps one whose action is in flight', async () => {
    const opened = userContext(true)
    openModal(opened)
    expect(byId('modal')).not.toBeNull()

    const change = async (fn: () => void) => {
      await act(async () => {
        fn()
      })
    }
    const click = async (id: string) => {
      await change(() => {
        fireEvent.click(byId(id) as Element)
      })
    }

    await change(() => {
      opened.standingFrame({ userId: 1, accountStanding: erasing })
    })
    expect(byId('modal')).toBeNull()
    expect(byId('hilos-user-erasing')).not.toBeNull()

    cleanup()
    const flying = userContext(true)
    renderPage(flying)
    await click('hilos-user-block-open')
    expect(byId('modal')).not.toBeNull()

    let reject!: (reason: unknown) => void
    vi.spyOn(flying.actions, 'dispatch').mockImplementation(() => ({
      requestId: 'erasing-req',
      loading: createSignal(false),
      done: new Promise<ActionResult>((_, no) => {
        reject = no
      }),
    }))
    await click('hilos-user-lifecycle-confirm')

    await change(() => {
      flying.standingFrame({ userId: 1, accountStanding: erasing })
    })
    expect(byId('modal')).not.toBeNull()

    await change(() => {
      reject(
        new ActionError(
          'hilos_user_block_set',
          'fail',
          'This account is being deleted.',
        ),
      )
    })
    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-user-lifecycle-error')?.textContent).toContain(
      'This account is being deleted.',
    )
  })

  it('says the refusal in the cancel-deletion window opened before the erasure began and closes it when the erasure arrives (HIL-1959)', async () => {
    const context = userContext(true)
    renderPage(context)
    const change = async (fn: () => void) => {
      await act(async () => {
        fn()
      })
    }
    const click = async (id: string) => {
      await change(() => {
        fireEvent.click(byId(id) as Element)
      })
    }

    await change(() => {
      context.standingFrame({
        userId: 1,
        accountStanding: standing({
          shown: 'deletion_scheduled',
          deletionEffectiveAt: 100_000_000,
        }),
      })
    })
    expect(byId('hilos-user-deletion-open')?.textContent).toContain(
      'Cancel deletion',
    )

    await click('hilos-user-deletion-open')
    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-user-lifecycle-step-up')).toBeNull()

    vi.spyOn(context.actions, 'dispatch').mockImplementation(() => ({
      requestId: 'cancel-deletion-req',
      loading: createSignal(false),
      done: Promise.reject<ActionResult>(
        new ActionError(
          'hilos_user_deletion_set',
          'fail',
          'This account is being deleted.',
        ),
      ),
    }))
    await click('hilos-user-lifecycle-confirm')
    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-user-lifecycle-error')?.textContent).toContain(
      'This account is being deleted.',
    )
    expect(
      (byId('hilos-user-lifecycle-confirm') as HTMLButtonElement).disabled,
    ).toBe(false)
    expect(byId('hilos-user-deletion-state')?.textContent).not.toContain(
      'No deletion is scheduled',
    )

    await change(() => {
      context.standingFrame({ userId: 1, accountStanding: erasing })
    })
    expect(byId('modal')).toBeNull()
    expect(byId('hilos-user-erasing')).not.toBeNull()
    expect(byId('hilos-user-deletion-open')).toBeNull()
  })
})

describe('HilosUserPage merge section (HIL-2010)', () => {
  const releases: Array<() => void> = []

  afterEach(() => {
    cleanup()
    for (const release of releases.splice(0)) release()
    document.body.classList.remove('modal-open')
    hilosToasts.clear()
  })

  /**
   * The server's merge progress; each case varies only its relevant facts.
   *
   * @param facts Fields that differ from one unfinished preparing duty.
   */
  function mergeWire(
    facts: Record<string, unknown> = {},
  ): Record<string, unknown> {
    return {
      operationId: 40,
      survivorUserId: 1,
      loserUserId: 74,
      state: 'preparing',
      stage: null,
      outcome: null,
      given: false,
      received: false,
      choice: null,
      refusal: null,
      parts: [
        {
          participant: 'prepare_survivor',
          owner: 'auth',
          userId: 74,
          done: false,
        },
      ],
      waits: [{ participant: 'prepare_loser', userId: 74 }],
      ...facts,
    }
  }

  /**
   * Bind the actual admin verdict before the card consumes its snapshot.
   *
   * @param viewer Whether this reader only views the section.
   * @param seed Whether the detail row has already arrived.
   */
  function cardContext(viewer = false, seed = true) {
    const context = userContext(seed)
    context.scopes.session.data.set('currentUser', {
      type: USER_ENTITY_TYPE,
      id: 99,
    })
    context.scopes.session.entities.upsert(
      { type: USER_ENTITY_TYPE, id: 99 },
      { id: 99, admin: !viewer },
    )
    context.scopes.session.data.set('adminViewMode', viewer)
    releases.push(bindAdminAccess(context.scopes))

    return context
  }

  function lines(id: string): string[] {
    return Array.from(document.querySelectorAll(`[data-id="${id}"]`)).map(
      (line) => line.textContent ?? '',
    )
  }

  it('reads the first snapshot beside the existing erasure and sessions without enabling merge actions', () => {
    const context = cardContext()
    context.scopes.page()!.data.set(HILOS_USER_MERGE_SECTION, mergeWire())
    context.scopes.page()!.data.set(HILOS_USER_ERASURE_SECTION, {
      startedAt: 1_800_000_000_000,
      parts: [
        { participant: 'messages', owner: 'chat', userId: 7, done: false },
      ],
      waits: [],
      refusals: [],
    })
    renderPage(context)
    expect(byId('hilos-user-merge-status')?.textContent).toBe('Preparing merge')
    expect(lines('hilos-user-merge-part')).toEqual([
      'Prepare receiving account · #74',
    ])
    expect(lines('hilos-user-merge-wait')).toEqual([
      'Prepare source account · #74',
    ])
    expect(lines('hilos-user-erasure-part')).toEqual([
      'messages · #7 — Expected',
    ])
    expect(byId('hilos-user-sessions')).not.toBeNull()
    expect(byId('hilos-user-merge-zone')).toBeNull()
    expect(context.sent).toEqual([])
  })

  it('waits for the detail signal before projecting a snapshot', async () => {
    const context = cardContext(false, false)
    const page = context.scopes.page()!
    page.data.set(HILOS_USER_MERGE_SECTION, mergeWire())
    renderPage(context)
    expect(byId('hilos-user-merge')).toBeNull()
    await act(async () => {
      page.entities.upsert(
        { type: USER_ENTITY_TYPE, id: 1 },
        { id: 1, name: 'Alice', lastActivity: null },
      )
      page.tables.upsert('userDetail', 1, {
        users: { type: USER_ENTITY_TYPE, id: 1 },
        connections: { presence: 'offline', onlineSessionCount: 0 },
      })
    })
    expect(byId('hilos-user-merge-status')?.textContent).toBe('Preparing merge')
    expect(lines('hilos-user-merge-part')).toEqual([
      'Prepare receiving account · #74',
    ])
  })

  it('follows live progress and clears the previous refusal and reading', async () => {
    const context = cardContext()
    renderPage(context)
    const frame = byId('hilos-user-merge')
    expect(byId('hilos-user-merge-status')?.textContent).toBe('')
    for (const [facts, expected] of [
      [{}, 'Preparing merge'],
      [{ state: 'wait', stage: 'go' }, 'Merge in progress'],
      [{ state: 'wait', stage: 'cancelling' }, 'Releasing merge reservations'],
      [
        {
          state: 'refusal',
          refusal: {
            code: 'occupied',
            reason: 'The source account is reserved',
            permanent: false,
          },
        },
        'Merge refused',
      ],
      [{ state: 'end', outcome: HIDDEN_VALUE }, 'Merge finished'],
    ] as const) {
      await act(async () =>
        context.standingFrame({ userId: 1, accountMerge: mergeWire(facts) }),
      )
      expect(byId('hilos-user-merge-status')?.textContent).toBe(expected)
      expect(byId('hilos-user-merge')).toBe(frame)
    }
    expect(byId('hilos-user-merge-refusal')).toBeNull()
    await act(async () =>
      context.standingFrame({ userId: 1, accountMerge: null }),
    )
    expect(byId('hilos-user-merge-status')?.textContent).toBe('')
    expect(lines('hilos-user-merge-part')).toEqual([])
    expect(lines('hilos-user-merge-wait')).toEqual([])
    expect(byId('hilos-user-merge-refusal')).toBeNull()
    expect(context.sent).toEqual([])
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('remounts from the current snapshot instead of retaining the previous operation', () => {
    const old = cardContext()
    old.scopes.page()!.data.set(HILOS_USER_MERGE_SECTION, mergeWire())
    const view = renderPage(old)
    expect(byId('hilos-user-merge-status')?.textContent).toBe('Preparing merge')
    view.unmount()
    const current = cardContext()
    current.scopes.page()!.data.set(
      HILOS_USER_MERGE_SECTION,
      mergeWire({
        state: 'wait',
        stage: 'go',
        parts: [],
        waits: [{ participant: 'give', userId: 8 }],
      }),
    )
    renderPage(current)
    expect(byId('hilos-user-merge-status')?.textContent).toBe(
      'Merge in progress',
    )
    expect(lines('hilos-user-merge-part')).toEqual([])
    expect(lines('hilos-user-merge-wait')).toEqual([
      'Transfer account data · #8',
    ])
  })

  it('shows the viewer status without hidden accounts, outcomes or a previous refusal', async () => {
    const context = cardContext(true)
    const hiddenProgress = mergeWire({
      operationId: HIDDEN_VALUE,
      survivorUserId: HIDDEN_VALUE,
      loserUserId: HIDDEN_VALUE,
      state: 'refusal',
      stage: HIDDEN_VALUE,
      outcome: HIDDEN_VALUE,
      choice: HIDDEN_VALUE,
      refusal: HIDDEN_VALUE,
      parts: [
        {
          participant: 'give',
          owner: 'user',
          userId: HIDDEN_VALUE,
          done: false,
        },
      ],
      waits: [{ participant: 'receive', userId: HIDDEN_VALUE }],
    })
    context.scopes.page()!.data.set(HILOS_USER_MERGE_SECTION, hiddenProgress)
    renderPage(context)
    expect(byId('hilos-user-merge-status')?.textContent).toBe('Merge refused')
    expect(lines('hilos-user-merge-part')).toEqual(['Transfer account data'])
    expect(lines('hilos-user-merge-wait')).toEqual(['Accept account data'])
    expect(byId('hilos-user-merge-refusal')).toBeNull()
    expect(byId('hilos-user-merge')?.textContent).not.toMatch(/#\d|_hidden/)
    await act(async () =>
      context.standingFrame({
        userId: 1,
        accountMerge: { ...hiddenProgress, state: 'end' },
      }),
    )
    expect(byId('hilos-user-merge-status')?.textContent).toBe('Merge finished')
    expect(context.sent).toEqual([])
  })

  it('keeps waiting on a merged card and takes its link from standing', async () => {
    const context = cardContext()
    renderPage(context, router({ resolvePath: resolveHilosPath }))
    await act(async () =>
      context.standingFrame({
        userId: 1,
        accountStanding: standing({
          shown: 'merged',
          blocked: true,
          mergedInto: 9,
          mergedIntoName: 'Bob',
        }),
        accountMerge: mergeWire({
          state: 'wait',
          survivorUserId: 5,
          parts: [],
          waits: [{ participant: 'give', userId: 8 }],
        }),
      }),
    )
    expect(byId('hilos-user-merged')?.textContent).toContain('Bob')
    expect(byId('hilos-user-merged-link')?.getAttribute('href')).toBe(
      resolveHilosPath(HilosPages.USER, { userId: '9' }),
    )
    expect(byId('hilos-user-merge-status')?.textContent).toBe(
      'Merge in progress',
    )
    expect(lines('hilos-user-merge-wait')).toEqual([
      'Transfer account data · #8',
    ])
    expect(byId('hilos-user-edit')).toBeNull()
    expect(byId('hilos-user-merge-zone')).toBeNull()
    expect(context.sent).toEqual([])
    expect(hilosToasts.toasts.get()).toEqual([])
  })
})

describe('HilosUserPage merge observation, draft and cancellation', () => {
  const releases: Array<() => void> = []

  afterEach(() => {
    cleanup()
    for (const release of releases.splice(0)) release()
    document.body.classList.remove('modal-open')
    hilosToasts.clear()
  })

  /**
   * Recorded progress with the choices the server accepted.
   *
   * @param facts Facts changed by this scenario.
   */
  function progress(
    facts: Partial<HilosAccountMergeProgress> = {},
  ): HilosAccountMergeProgress {
    return {
      operationId: 40,
      survivorUserId: 1,
      loserUserId: 2,
      state: 'preparing',
      stage: 'preparing',
      outcome: null,
      given: false,
      received: false,
      choice: {
        passwordFate: null,
        secondFactorFate: null,
        expectedSurvivorHasSecondFactor: false,
        expectedLoserHasSecondFactor: false,
      },
      refusal: null,
      parts: [{ participant: 'give', owner: 'user', userId: 2, done: false }],
      waits: [{ participant: 'sessions', userId: 2 }],
      ...facts,
    }
  }

  /**
   * Bind the real access verdict and control frames and replies independently.
   *
   * @param enabled Whether the project allows merge mutations.
   * @param snapshot The initial merge, if any.
   * @param options Candidate and credential facts for the draft.
   */
  function setup(
    enabled = false,
    snapshot: unknown = progress(),
    options: Parameters<typeof userContext>[3] = {},
  ) {
    const context = userContext(true, enabled, 'skip', options)
    const { scopes } = context
    scopes.session.data.set('adminViewMode', true)
    scopes.session.data.set('currentUser', { type: USER_ENTITY_TYPE, id: 99 })
    scopes.session.entities.upsert(
      { type: USER_ENTITY_TYPE, id: 99 },
      { id: 99, admin: true },
    )
    releases.push(bindAdminAccess(scopes))
    scopes.page()!.data.set(HILOS_USER_MERGE_SECTION, snapshot)
    scopes.page()!.data.set(HILOS_USER_MERGE_AVAILABILITY_SECTION, {
      reserved: false,
      available: true,
    })
    return {
      ...context,
      update(accountMerge: unknown, accountStanding = standing()) {
        context.standingFrame({
          userId: 1,
          accountStanding,
          accountMerge,
          accountMergeAvailability: { reserved: false, available: true },
        })
      },
      access(admin: boolean) {
        scopes.session.entities.upsert(
          { type: USER_ENTITY_TYPE, id: 99 },
          { admin },
        )
      },
    }
  }

  /** The server's viewer frame with every private fact withheld. */
  function hiddenProgress(): Record<string, unknown> {
    return {
      ...progress(),
      operationId: HIDDEN_VALUE,
      survivorUserId: HIDDEN_VALUE,
      loserUserId: HIDDEN_VALUE,
      stage: HIDDEN_VALUE,
      outcome: HIDDEN_VALUE,
      choice: HIDDEN_VALUE,
      refusal: HIDDEN_VALUE,
      parts: [],
      waits: [],
    }
  }

  async function click(id: string): Promise<void> {
    await act(async () => fireEvent.click(byId(id) as Element))
  }

  function dialog(): HTMLElement {
    return document.querySelector('[role="dialog"]') as HTMLElement
  }

  it('observes without step-up, candidate requests or a mutation flag and keeps live regions and focus', async () => {
    const world = setup()
    renderPage(world)
    await click('hilos-user-merge-view')
    const modal = dialog()
    expect(modal.getAttribute('aria-modal')).toBe('true')
    expect(modal.getAttribute('aria-label')).toBe('Account merge')
    expect(document.activeElement).toBe(modal)
    expect(modal.textContent).toContain('Transfer account data · #2')
    expect(modal.textContent).toContain('Close source sessions · #2')
    expect(byId('hilos-user-merge-step-up')).toBeNull()
    expect(byId('hilos-user-merge-next')).toBeNull()
    expect(byId('hilos-user-merge-operation-cancel')).toBeNull()
    expect(world.connection.sendTableViewport).not.toHaveBeenCalled()
    const status = modal.querySelector('[role="status"]')!
    const alert = modal.querySelector('[role="alert"]')!
    byId('hilos-user-merge-close')!.focus()
    await act(async () =>
      world.update(progress({ state: 'wait', stage: 'go' })),
    )
    expect(status.textContent).toBe('Merge in progress')
    const reason = 'The other account is reserved elsewhere.'
    await act(async () =>
      world.update(
        progress({ state: 'refusal', refusal: { code: 'occupied', reason } }),
      ),
    )
    expect(alert.textContent).toBe(reason)
    expect(byId('hilos-user-merge-operation-refusal')?.textContent).toBe(reason)
    await act(async () =>
      world.update(
        progress({ state: 'end', outcome: 'completed', parts: [], waits: [] }),
      ),
    )
    expect(status.textContent).toBe('Merge completed')
    expect(modal.querySelector('[role="status"]')).toBe(status)
    expect(modal.querySelector('[role="alert"]')).toBe(alert)
    expect(document.activeElement).toBe(byId('hilos-user-merge-close'))
    expect(world.sent).toEqual([])
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it.each(['hilos-user-merge-close', 'modal-close', 'Escape'])(
    'closes by %s and reopens from fresh data without dispatch or discard',
    async (control) => {
      const world = setup(true)
      renderPage(world)
      await click('hilos-user-merge-view')
      if (control === 'Escape')
        await act(async () => fireEvent.keyDown(dialog(), { key: 'Escape' }))
      else await click(control)
      expect(byId('modal')).toBeNull()
      expect(byId('modal-confirm-discard')).toBeNull()
      await act(async () =>
        world.update(progress({ state: 'wait', stage: 'go' })),
      )
      await click('hilos-user-merge-view')
      expect(dialog().textContent).toContain('Merge in progress')
      expect(world.sent).toEqual([])
    },
  )

  it('shows unavailable and changed messages without keeping an old pair', async () => {
    const world = setup()
    renderPage(world)
    await click('hilos-user-merge-view')
    await act(async () => world.update(null))
    expect(dialog().textContent).toContain(
      HILOS_ACCOUNT_MERGE_WINDOW_COPY.unavailable,
    )
    expect(dialog().querySelector('dd')).toBeNull()
    await act(async () => world.update(progress({ operationId: 41 })))
    expect(dialog().textContent).toContain(
      HILOS_ACCOUNT_MERGE_WINDOW_COPY.changed,
    )
    await click('hilos-user-merge-close')
    await click('hilos-user-merge-view')
    expect(dialog().textContent).toContain('Preparing merge')
    await act(async () => world.removeRow())
    expect(byId('modal')).toBeNull()
  })

  it('keeps observation through a tombstone and draws recorded choices with privacy on downgrade', async () => {
    const world = setup(
      true,
      progress({
        choice: {
          passwordFate: 'loser',
          secondFactorFate: 'both',
          expectedSurvivorHasSecondFactor: false,
          expectedLoserHasSecondFactor: true,
        },
      }),
    )
    renderPage(world)
    await click('hilos-user-merge-view')
    const modal = dialog()
    expect(
      Array.from(modal.querySelectorAll('dd'), (entry) => entry.textContent),
    ).toEqual(['#1', '#2'])
    expect(modal.textContent).toContain('The other account password')
    expect(modal.textContent).toContain(
      'Transfer the other account’s protection',
    )
    await act(async () =>
      world.update(
        progress({ state: 'end', outcome: 'completed' }),
        standing({
          shown: 'merged',
          mergedInto: 9,
          mergedIntoName: 'Survivor',
        }),
      ),
    )
    expect(dialog()).toBe(modal)
    expect(byId('hilos-user-merge-zone')).toBeNull()
    expect(byId('hilos-user-sessions')).not.toBeNull()
    await act(async () => world.access(false))
    expect(dialog()).toBe(modal)
    expect(modal.textContent).toContain(
      HILOS_ACCOUNT_MERGE_WINDOW_COPY.unavailable,
    )
    await act(async () => world.update(hiddenProgress()))
    expect(
      Array.from(modal.querySelectorAll('dd'), (entry) => entry.textContent),
    ).toEqual(['Hidden', 'Hidden'])
    expect(modal.textContent).not.toContain('The other account password')
    expect(byId('hilos-user-merge-operation-cancel')).toBeNull()
  })

  it('keeps active operations and unknown availability out of a new draft', async () => {
    const world = setup(true)
    renderPage(world)
    expect((byId('hilos-user-merge-open') as HTMLButtonElement).disabled).toBe(
      true,
    )
    await click('hilos-user-merge-open')
    expect(byId('modal')).toBeNull()
    expect(world.sent).toEqual([])
    await act(async () =>
      world.update(progress({ state: 'refusal', outcome: 'not_started' })),
    )
    expect((byId('hilos-user-merge-open') as HTMLButtonElement).disabled).toBe(
      false,
    )
    await act(async () =>
      world.scopes
        .page()!
        .data.set(HILOS_USER_MERGE_AVAILABILITY_SECTION, HIDDEN_VALUE),
    )
    expect((byId('hilos-user-merge-open') as HTMLButtonElement).disabled).toBe(
      true,
    )
  })

  it('disables an occupied candidate and rejects a removed candidate before Apply', async () => {
    const world = setup(true, null, { candidateAvailable: false })
    renderPage(world)
    await click('hilos-user-merge-open')
    expect((byId('hilos-user-merge-row-2') as HTMLInputElement).disabled).toBe(
      true,
    )
    await click('hilos-user-merge-row-2')
    expect((byId('hilos-user-merge-next') as HTMLButtonElement).disabled).toBe(
      true,
    )
    await act(async () =>
      world.updateCandidate({
        accountMergeAvailability: { reserved: false, available: true },
      }),
    )
    await click('hilos-user-merge-row-2')
    await click('hilos-user-merge-next')
    await act(async () => world.removeCandidate())
    expect(byId('hilos-user-merge-gone')).not.toBeNull()
    expect(mergeConfirm().disabled).toBe(true)
    await click('hilos-user-merge-confirm')
    expect(
      world.sent.filter((entry) => entry.action === 'hilos_user_merge'),
    ).toEqual([])
  })

  it('resets password and second-factor choices independently when the live pair changes', async () => {
    const world = setup(true, null, {
      detailHasPassword: true,
      candidateHasPassword: true,
      detailHasSecondFactor: true,
      candidateHasSecondFactor: true,
    })
    renderPage(world)
    await openMergeOnBob()
    await click('hilos-user-merge-fate-survivor')
    await click('hilos-user-merge-second-factor-survivor')
    await act(async () =>
      world.updateCandidate({ unverifiedPasswordAddress: 'new@example.test' }),
    )
    expect(
      (byId('hilos-user-merge-fate-survivor') as HTMLInputElement).checked,
    ).toBe(false)
    expect(
      (byId('hilos-user-merge-second-factor-survivor') as HTMLInputElement)
        .checked,
    ).toBe(true)
    await click('hilos-user-merge-fate-survivor')
    await act(async () => world.updateCandidate({ hasSecondFactor: false }))
    expect(byId('hilos-user-merge-second-factor-survivor')).toBeNull()
    expect(
      (byId('hilos-user-merge-fate-survivor') as HTMLInputElement).checked,
    ).toBe(true)
    await act(async () => world.updateCandidate({ hasSecondFactor: true }))
    expect(
      (byId('hilos-user-merge-second-factor-survivor') as HTMLInputElement)
        .checked,
    ).toBe(false)
  })

  it.each(['accepted', 'refused', 'unanswered'] as const)(
    'binds the %s start outcome to the same action handle',
    async (outcome) => {
      const world = setup(true, null)
      renderPage(world)
      await openMergeOnBob()
      await click('hilos-user-merge-confirm')
      expect(
        world.sent.filter((entry) => entry.action === 'hilos_user_merge'),
      ).toHaveLength(1)
      await act(async () => {
        if (outcome === 'accepted')
          world.answerMerge(undefined, { operationId: 40 })
        else if (outcome === 'refused') world.answerMerge('Merge refused')
        else world.disconnect()
      })
      if (outcome === 'accepted') {
        expect(byId('hilos-user-merge-close')).not.toBeNull()
        expect(byId('hilos-user-merge-confirm')).toBeNull()
        expect(hilosToasts.toasts.get()).toEqual([])
        await act(async () => world.update(progress()))
        expect(dialog().textContent).toContain('Preparing merge')
      } else if (outcome === 'refused') {
        expect(byId('hilos-action-error')?.textContent).toBe('Merge refused')
        expect(byId('hilos-user-merge-confirm')).not.toBeNull()
      } else {
        expect(dialog().querySelector('[role="status"]')?.textContent).toBe(
          HILOS_ACCOUNT_MERGE_WINDOW_COPY.unanswered,
        )
        expect(byId('hilos-action-error')).toBeNull()
        expect(mergeConfirm().disabled).toBe(false)
        await act(async () => world.update(progress()))
        expect(byId('hilos-user-merge-close')).not.toBeNull()
      }
    },
  )

  it.each([
    { name: 'preparing', enabled: true, facts: {}, offered: true },
    { name: 'viewer', enabled: true, facts: {}, viewer: true, offered: false },
    { name: 'disabled project', enabled: false, facts: {}, offered: false },
    {
      name: 'cancelling',
      enabled: true,
      facts: { stage: 'cancelling' },
      offered: false,
    },
    { name: 'given', enabled: true, facts: { given: true }, offered: false },
    {
      name: 'ended',
      enabled: true,
      facts: { state: 'end', outcome: 'completed' },
      offered: false,
    },
  ] as const)(
    'offers cancellation only when allowed: $name',
    async (scenario) => {
      const world = setup(scenario.enabled, progress(scenario.facts))
      if ('viewer' in scenario) {
        world.access(false)
        world.scopes
          .page()!
          .data.set(HILOS_USER_MERGE_SECTION, hiddenProgress())
      }
      renderPage(world)
      await click('hilos-user-merge-view')
      expect(byId('hilos-user-merge-operation-cancel') !== null).toBe(
        scenario.offered,
      )
      expect(world.sent).toEqual([])
    },
  )

  it('sends only the operation id, stays pending through acceptance and closes on the live canceled end', async () => {
    const world = setup(true)
    renderPage(world)
    await click('hilos-user-merge-view')
    await click('hilos-user-merge-operation-cancel')
    expect(world.sent).toEqual([
      {
        action: 'hilos_user_merge_cancel',
        data: { operationId: 40 },
        requestId: expect.any(String),
      },
    ])
    expect(
      byId('hilos-user-merge-operation-cancel')?.getAttribute('aria-busy'),
    ).toBe('true')
    await click('hilos-user-merge-operation-cancel')
    expect(world.sent).toHaveLength(1)
    await act(async () => world.answerMergeCancel())
    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-user-merge-operation-cancel')).toBeNull()
    await act(async () =>
      world.update(progress({ state: 'wait', stage: 'cancelling' })),
    )
    expect(dialog().textContent).toContain('Releasing merge reservations')
    await act(async () =>
      world.update(
        progress({
          state: 'end',
          stage: 'cancelling',
          outcome: 'not_started',
          parts: [],
          waits: [],
        }),
      ),
    )
    expect(byId('modal')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([
      expect.objectContaining({ message: 'Merge canceled' }),
    ])
  })

  it('keeps a request refusal verbatim beside live progress and keeps a business refusal after acceptance', async () => {
    const world = setup(true)
    renderPage(world)
    await click('hilos-user-merge-view')
    await click('hilos-user-merge-operation-cancel')
    const reason = 'The merge can no longer be canceled'
    await act(async () => world.answerMergeCancel(reason))
    expect(byId('hilos-user-merge-cancel-refusal')?.textContent).toContain(
      reason,
    )
    expect(byId('hilos-user-merge-operation-refusal')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
    await act(async () =>
      world.update(progress({ state: 'wait', stage: 'go' })),
    )
    expect(dialog().textContent).toContain('Merge in progress')
    await click('hilos-user-merge-close')
    await click('hilos-user-merge-view')
    expect(byId('hilos-user-merge-cancel-refusal')?.textContent).not.toContain(
      reason,
    )
    await click('hilos-user-merge-operation-cancel')
    await act(async () => world.answerMergeCancel())
    await act(async () =>
      world.update(
        progress({
          state: 'end',
          outcome: 'not_started',
          refusal: { code: 'occupied', reason: 'A participant refused.' },
        }),
      ),
    )
    expect(byId('modal')).not.toBeNull()
    expect(byId('hilos-user-merge-operation-refusal')?.textContent).toBe(
      'A participant refused.',
    )
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('isolates a reopened observation from the old pending request and its late refusal', async () => {
    const world = setup(true)
    renderPage(world)
    await click('hilos-user-merge-view')
    await click('hilos-user-merge-operation-cancel')
    const oldRequest = world.sent.at(-1)!.requestId!
    await click('hilos-user-merge-close')
    await click('hilos-user-merge-view')
    await click('hilos-user-merge-operation-cancel')
    const currentRequest = world.sent.at(-1)!.requestId!
    expect(currentRequest).not.toBe(oldRequest)
    await act(async () =>
      world.answerMergeCancel('Old request refused.', oldRequest),
    )
    expect(dialog().textContent).not.toContain('Old request refused.')
    expect(
      byId('hilos-user-merge-operation-cancel')?.getAttribute('aria-busy'),
    ).toBe('true')
    await act(async () =>
      world.answerMergeCancel(
        'The merge can no longer be canceled',
        currentRequest,
      ),
    )
    expect(byId('hilos-user-merge-cancel-refusal')?.textContent).toContain(
      'The merge can no longer be canceled',
    )
  })

  it('releases cancellation after a dropped reply so it can be requested again', async () => {
    const world = setup(true)
    renderPage(world)
    await click('hilos-user-merge-view')
    await click('hilos-user-merge-operation-cancel')
    await act(async () => world.disconnect())
    expect(byId('hilos-user-merge-cancel-refusal')?.textContent).toContain(
      'The connection dropped',
    )
    expect(
      byId('hilos-user-merge-operation-cancel')?.getAttribute('aria-busy'),
    ).toBeNull()
    await click('hilos-user-merge-operation-cancel')
    expect(world.sent).toHaveLength(2)
  })
})

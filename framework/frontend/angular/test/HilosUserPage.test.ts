// The Angular peer of vue/src/admin/users/HilosUserPage.test.ts and
// react/test/HilosUserPage.test.tsx (HIL-1050), under the same case names: the
// rename modal on the shared row-edit helper. A viewer of the admin view mode
// (HIL-1260, HIL-1263) sees the mark in place of a hidden value, opens every
// window at once, and finds the confirmation in it disabled by the mode.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  type TableViewportController,
  ActionLifecycle,
  ActionError,
  type ActionResult,
  applyServerTime,
  bindAdminAccess,
  bindSessionScope,
  formatCalendarDate,
  formatMoment,
  HIDDEN_VALUE,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosConnection,
  HilosPages,
  hilosToasts,
  resolveHilosPath,
  ScopeManager,
  createSignal,
  entityCollection,
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
  type HilosAccountMergeAvailability,
  type HilosAccountMergeProgress,
  type HilosUsersContext,
} from '@hilos/core/admin/users'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { HilosUserPage } from '../src/admin/users/HilosUserPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { bindTestTimeZone } from './timeZoneTestHarness.js'

function router(overrides: Partial<HilosRouter> = {}): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.USER,
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

/**
 * The single-user detail arrives as the one row of the `userDetail` table, its
 * name through the `user` entity — so a rename elsewhere is an entity upsert and
 * a deleted user is the row leaving the table.
 */
/** The refusal a window meets when its administrator has nothing to confirm with. */
const STEP_UP_REFUSAL =
  'Add a password, an email or a phone to your account to do this'

/**
 * A window that takes something away opens on the server's word about the
 * administrator's confirmation (HIL-1275); `stepUp` is that word.
 */
function userContext(
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
    candidateMergeAvailability?: HilosAccountMergeAvailability | null
    detailTimeZone?: string | null
    detailTimeZoneHidden?: boolean
    impersonation?: Record<string, unknown>
    currentUserId?: number
  } = {},
): {
  context: HilosUsersContext
  scopes: ScopeManager
  renameElsewhere: (name: string) => void
  removeRow: () => void
  failRename: () => void
  standingFrame: (data: Record<string, unknown>) => void
  answerImpersonate: (reason?: string) => void
  answerMerge: (reason?: string, reply?: unknown) => void
  answerMergeCancel: (reason?: string, requestId?: string) => void
  removeCandidate: () => void
  updateCandidate: (merge: Record<string, unknown>) => void
  access: (admin: boolean) => void
  sent: Array<{ action: string; data: unknown; requestId?: string }>
} {
  const scopes = new ScopeManager()
  const page = scopes.openPage(HilosPages.USER)
  // The installation's impersonation settings ride the card's first answer (HIL-1170).
  if (options.impersonation !== undefined) {
    page.data.set('impersonation', options.impersonation)
  }
  const currentUserId = options.currentUserId ?? 99
  scopes.session.data.set('currentUser', {
    type: USER_ENTITY_TYPE,
    id: currentUserId,
  })
  scopes.session.entities.upsert(
    { type: USER_ENTITY_TYPE, id: currentUserId },
    {
      id: currentUserId,
      admin:
        options.currentUserId === undefined || options.currentUserId === 99,
    },
  )
  page.entities.upsert(
    { type: 'user', id: 1 },
    {
      id: 1,
      name: 'Alice',
      lastActivity: null,
      ...(options.detailTimeZoneHidden
        ? { timeZone: { _hidden: true } }
        : options.detailTimeZone !== undefined
          ? { timeZone: options.detailTimeZone }
          : {}),
    },
  )
  page.tables.upsert('userDetail', 1, {
    users: { type: 'user', id: 1 },
    connections: { presence: 'online', onlineSessionCount: 1 },
    secondFactors: { hasSecondFactor: options.detailHasSecondFactor ?? false },
    identities: {
      hasPassword: options.detailHasPassword ?? false,
      unverifiedPasswordAddress:
        options.detailUnverifiedPasswordAddress ?? null,
    },
  })
  // The candidate's name arrives through the `user` entity, so a viewer of the
  // admin view mode may be sent it hidden.
  page.entities.upsert(
    { type: 'user', id: 2 },
    {
      id: 2,
      name: options.candidateNameHidden ? { _hidden: true } : 'Bob',
      lastActivity: null,
    },
  )
  const users = entityCollection(scopes, USER_ENTITY_TYPE, userFromFields)
  const connection = new HilosConnection({ url: 'ws://test/ws' })
  const sent: Array<{ action: string; data: unknown; requestId?: string }> = []
  const actionListeners = new Map<string, Array<(signal: unknown) => void>>()
  const candidateMerge: Record<string, unknown> = {
    accountMergeAvailability: options.candidateMergeAvailability ?? {
      reserved: false,
      available: true,
    },
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
  }
  const candidateRows = () => [
    {
      rowKey: 2,
      slots: {
        users: { type: 'user', id: 2 },
        merge: { ...candidateMerge },
      },
    },
    {
      rowKey: 99,
      slots: {
        users: { id: 99, name: 'Admin', lastActivity: null },
        merge: {
          accountMergeAvailability: { reserved: false, available: true },
          identities: [],
          hasPassword: false,
          hasSecondFactor: false,
        },
      },
    },
  ]
  const tableWindowListeners: Array<(signal: unknown) => void> = []
  const tableDeltaListeners: Array<(signal: unknown) => void> = []
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
    context: {
      scopes,
      connection,
      actions: new ActionLifecycle(connection),
      users,
      accountMerge,
    },
    scopes,
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
      for (const listener of actionListeners.get(signal.kind) ?? []) {
        listener(signal)
      }
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
    updateCandidate(merge: Record<string, unknown>): void {
      Object.assign(candidateMerge, merge)
      const signal = {
        data: {
          page: HilosPages.USER,
          tableKey: 'mergeCandidates',
          kind: 'row_updated',
          rowKey: '2',
          row: candidateRows()[0],
        },
      }
      for (const listener of tableDeltaListeners) {
        listener(signal)
      }
    },
    access(admin: boolean): void {
      scopes.session.entities.upsert(
        { type: USER_ENTITY_TYPE, id: 99 },
        { id: 99, admin },
      )
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

/** Mount the page and open the rename modal. */
function openModal(
  context: HilosUsersContext,
): ComponentFixture<HilosUserPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosUserPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  el(fixture, 'hilos-user-edit')?.click()
  fixture.detectChanges()

  return fixture
}

function el(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

function nameInput(fixture: ComponentFixture<unknown>): HTMLInputElement {
  return el(fixture, 'hilos-user-name-input') as HTMLInputElement
}

function saveButton(fixture: ComponentFixture<unknown>): HTMLButtonElement {
  return el(fixture, 'hilos-user-save') as HTMLButtonElement
}

/** Let the step's answer arrive and draw what it opened. */
async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await new Promise((done) => setTimeout(done, 0))
  fixture.detectChanges()
  await fixture.whenStable()
  fixture.detectChanges()
}

function typeDraft(fixture: ComponentFixture<unknown>, text: string): void {
  const input = nameInput(fixture)
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  fixture.detectChanges()
}

function mergeConfirm(fixture: ComponentFixture<unknown>): HTMLButtonElement {
  return el(fixture, 'hilos-user-merge-confirm') as HTMLButtonElement
}

/** Mount the card, open its merge window and take Bob to the second step. */
async function openMergeOnBob(
  context: HilosUsersContext,
): Promise<ComponentFixture<HilosUserPage>> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosUserPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  for (const id of [
    'hilos-user-merge-open',
    'hilos-user-merge-row-2',
    'hilos-user-merge-next',
  ]) {
    el(fixture, id)?.click()
    await settle(fixture)
  }

  return fixture
}

describe('HilosUserPage rename modal', () => {
  it('shows the merge zone only when enabled and locks Next without a candidate', async () => {
    const fixture = openModal(userContext().context)
    expect(el(fixture, 'hilos-user-merge-zone')).toBeNull()

    fixture.componentRef.setInput('context', userContext(true).context)
    fixture.detectChanges()
    expect(el(fixture, 'hilos-user-merge-zone')).not.toBeNull()
    el(fixture, 'hilos-user-merge-open')?.click()
    await settle(fixture)
    expect(
      (el(fixture, 'hilos-user-merge-next') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it("keeps a refused merge open in the server's words, then says the candidate is gone", async () => {
    const world = userContext(true)
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', world.context)
    fixture.detectChanges()

    el(fixture, 'hilos-user-merge-open')?.click()
    await settle(fixture)
    el(fixture, 'hilos-user-merge-row-2')?.click()
    await settle(fixture)
    el(fixture, 'hilos-user-merge-next')?.click()
    await settle(fixture)
    el(fixture, 'hilos-user-merge-confirm')?.click()
    await settle(fixture)
    world.answerMerge('Merge refused')
    await settle(fixture)

    expect(el(fixture, 'modal')).not.toBeNull()
    expect((fixture.nativeElement as HTMLElement).textContent).toContain(
      'Merge refused',
    )

    world.removeCandidate()
    await settle(fixture)
    expect(el(fixture, 'hilos-user-merge-gone')?.textContent).toContain(
      'No longer available',
    )
    expect(
      (el(fixture, 'hilos-user-merge-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  // Merge is locked by its own merge in flight, which never turns it off
  // natively; a reason of its own does — a password fate not chosen yet, the
  // other account gone from the list.
  it('turns Merge off for a missing password fate, apart from its own flight', async () => {
    const world = userContext(true, 'skip', {
      detailHasPassword: true,
      candidateHasPassword: true,
    })
    const fixture = await openMergeOnBob(world.context)
    const merges = () =>
      world.sent.filter((entry) => entry.action === 'hilos_user_merge')

    expect(mergeConfirm(fixture).disabled).toBe(true)
    expect(mergeConfirm(fixture).getAttribute('aria-busy')).toBeNull()
    mergeConfirm(fixture).click()
    await settle(fixture)
    expect(merges()).toHaveLength(0)

    el(fixture, 'hilos-user-merge-fate-survivor')?.click()
    await settle(fixture)
    expect(mergeConfirm(fixture).disabled).toBe(false)

    mergeConfirm(fixture).click()
    // Pressed again before change detection: the send guard holds.
    mergeConfirm(fixture).click()
    expect(merges()).toHaveLength(1)
    await settle(fixture)
    expect(mergeConfirm(fixture).disabled).toBe(false)
    expect(mergeConfirm(fixture).getAttribute('aria-busy')).toBe('true')
    expect(mergeConfirm(fixture).getAttribute('aria-disabled')).toBe('true')
    mergeConfirm(fixture).click()
    await settle(fixture)
    expect(merges()).toHaveLength(1)
  })

  it('turns Merge off when the other account goes while its merge flies', async () => {
    const world = userContext(true)
    const fixture = await openMergeOnBob(world.context)
    mergeConfirm(fixture).click()
    await settle(fixture)
    expect(mergeConfirm(fixture).disabled).toBe(false)
    expect(mergeConfirm(fixture).getAttribute('aria-busy')).toBe('true')

    world.removeCandidate()
    await settle(fixture)
    expect(el(fixture, 'hilos-user-merge-gone')).not.toBeNull()
    expect(mergeConfirm(fixture).disabled).toBe(true)
    expect(mergeConfirm(fixture).getAttribute('aria-busy')).toBe('true')

    world.answerMerge('Merge refused')
    await settle(fixture)
    expect(el(fixture, 'modal')).not.toBeNull()
    expect(mergeConfirm(fixture).disabled).toBe(true)
    expect(mergeConfirm(fixture).getAttribute('aria-busy')).toBeNull()
    mergeConfirm(fixture).click()
    await settle(fixture)
    expect(
      world.sent.filter((entry) => entry.action === 'hilos_user_merge'),
    ).toHaveLength(1)
  })

  it('opens on the committed name with save locked and the message line empty', () => {
    const { context } = userContext()
    const fixture = openModal(context)

    expect(nameInput(fixture).value).toBe('Alice')
    expect(saveButton(fixture).disabled).toBe(true)
    expect(el(fixture, 'hilos-user-edit-notice')).toBeNull()
    expect(el(fixture, 'hilos-user-edit-notice-idle')).not.toBeNull()
  })

  it('reloads a pristine edit when the name changes elsewhere and says so', () => {
    const { context, renameElsewhere } = userContext()
    const fixture = openModal(context)

    renameElsewhere('Alicia')
    fixture.detectChanges()

    expect(nameInput(fixture).value).toBe('Alicia')
    expect(el(fixture, 'conflict-badge')).toBeNull()
    expect(el(fixture, 'hilos-user-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton(fixture).disabled).toBe(true)

    // The person types over the taken name: the note about it goes out.
    typeDraft(fixture, 'Alicia B')
    expect(el(fixture, 'hilos-user-edit-notice')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(false)
  })

  it('sends nothing on Enter while a conflict stands', () => {
    const { context, renameElsewhere, sent } = userContext()
    const fixture = openModal(context)
    typeDraft(fixture, 'Mine')
    renameElsewhere('Theirs')
    fixture.detectChanges()

    nameInput(fixture).form?.dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    fixture.detectChanges()

    expect(sent).toHaveLength(0)
    expect(el(fixture, 'conflict-badge')).not.toBeNull()
  })

  it('surfaces a conflict on a dirty edit, hides merge, and Keep mine sends mine', () => {
    const { context, renameElsewhere, sent } = userContext()
    const fixture = openModal(context)
    typeDraft(fixture, 'Mine')

    renameElsewhere('Theirs')
    fixture.detectChanges()

    expect(el(fixture, 'conflict-badge')).not.toBeNull()
    expect(el(fixture, 'hilos-user-edit-notice')?.textContent).toContain(
      'Changed elsewhere to "Theirs"',
    )
    expect(el(fixture, 'conflict-merge')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(true)
    expect(nameInput(fixture).value).toBe('Mine')

    el(fixture, 'conflict-accept-mine')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'conflict-badge')).toBeNull()
    expect(el(fixture, 'hilos-user-edit-notice')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(false)

    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('hilos_user_update')
    expect(sent[0]?.data).toEqual({ id: 1, name: 'Mine' })
  })

  it('Take theirs sets the name to the live value, says so, and locks save', () => {
    const { context, renameElsewhere } = userContext()
    const fixture = openModal(context)
    typeDraft(fixture, 'Mine')
    renameElsewhere('Theirs')
    fixture.detectChanges()

    el(fixture, 'conflict-accept-theirs')?.click()
    fixture.detectChanges()

    expect(nameInput(fixture).value).toBe('Theirs')
    expect(el(fixture, 'conflict-badge')).toBeNull()
    expect(el(fixture, 'hilos-user-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton(fixture).disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', () => {
    const { context, removeRow } = userContext()
    const fixture = openModal(context)
    typeDraft(fixture, 'Mine')
    removeRow()
    fixture.detectChanges()

    expect(el(fixture, 'hilos-user-edit-notice')?.textContent).toContain(
      'Deleted elsewhere',
    )
    expect(saveButton(fixture).disabled).toBe(true)
    expect(saveButton(fixture).textContent?.trim()).toBe('Deleted')
    expect(nameInput(fixture).value).toBe('Mine')
    expect(el(fixture, 'modal')).not.toBeNull()
  })

  it('waits for the name it sent: Take theirs in flight keeps the modal open until that name lands', () => {
    const { context, renameElsewhere, sent } = userContext()
    const fixture = openModal(context)
    typeDraft(fixture, 'Mine')
    const field = nameInput(fixture)
    const modal = el(fixture, 'modal')!
    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent.at(-1)?.data).toEqual({ id: 1, name: 'Mine' })
    const close = el(fixture, 'modal-close') as HTMLButtonElement
    const cancel = el(fixture, 'hilos-user-cancel') as HTMLButtonElement
    expect(field.matches(':disabled')).toBe(true)
    expect(nameInput(fixture)).toBe(field)
    expect(close.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    close.click()
    cancel.click()
    modal.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    modal.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal')).toBe(modal)
    expect(el(fixture, 'modal-confirm')).toBeNull()

    renameElsewhere('Theirs')
    fixture.detectChanges()
    el(fixture, 'conflict-accept-theirs')?.click()
    fixture.detectChanges()
    // The draft now matches the live name, but that is not the name this rename sent.
    expect(nameInput(fixture).value).toBe('Theirs')
    expect(el(fixture, 'modal')).not.toBeNull()

    renameElsewhere('Other')
    fixture.detectChanges()
    expect(nameInput(fixture).value).toBe('Other')
    expect(el(fixture, 'modal')).not.toBeNull()

    renameElsewhere('Mine')
    fixture.detectChanges()
    expect(el(fixture, 'modal')).toBeNull()
  })

  it('forgets the name it sent once the rename is refused', () => {
    const { context, renameElsewhere, failRename } = userContext()
    const fixture = openModal(context)
    typeDraft(fixture, 'Mine')
    const field = nameInput(fixture)
    const modal = el(fixture, 'modal')!
    saveButton(fixture).click()
    fixture.detectChanges()
    const close = el(fixture, 'modal-close') as HTMLButtonElement
    const cancel = el(fixture, 'hilos-user-cancel') as HTMLButtonElement
    expect(field.matches(':disabled')).toBe(true)
    expect(close.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    close.click()
    cancel.click()
    modal.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    modal.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal')).toBe(modal)
    expect(el(fixture, 'modal-confirm')).toBeNull()

    failRename()
    fixture.detectChanges()
    expect(saveButton(fixture).disabled).toBe(false)
    expect(el(fixture, 'modal')).toBe(modal)
    expect(nameInput(fixture)).toBe(field)
    expect(field.value).toBe('Mine')
    expect(field.matches(':disabled')).toBe(false)
    expect(close.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(el(fixture, 'modal-confirm')).toBeNull()

    renameElsewhere('Mine')
    fixture.detectChanges()
    expect(el(fixture, 'modal')).not.toBeNull()
  })

  it('asks before discarding a changed draft', () => {
    const { context } = userContext()
    const fixture = openModal(context)
    typeDraft(fixture, 'Mine')

    el(fixture, 'modal-close')?.click()
    fixture.detectChanges()
    // The discard question is the modal's own confirm step: the dialog stays.
    expect(el(fixture, 'modal')).not.toBeNull()
    expect(el(fixture, 'modal-confirm-discard')).not.toBeNull()

    el(fixture, 'modal-confirm-discard')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal')).toBeNull()
  })
})

describe('HilosUserPage name hidden from a viewer of the admin view mode (HIL-1260)', () => {
  it('draws the mark and the person icon, and a rename window with the mark in place of the field', () => {
    const { context } = userContext()
    context.scopes
      .page()
      ?.entities.upsert({ type: 'user', id: 1 }, { name: { _hidden: true } })
    const fixture = openModal(context)

    expect(
      el(fixture, 'hilos-user-name')
        ?.querySelector('[data-id="hilos-hidden"]')
        ?.textContent?.trim(),
    ).toBe('Hidden')
    expect(
      el(fixture, 'hilos-avatar')?.querySelector('.bi-person'),
    ).not.toBeNull()
    expect(
      el(fixture, 'modal')?.querySelector('.modal-title')?.textContent,
    ).toBe('Rename · Hidden')
    expect(nameInput(fixture)).toBeNull()
    expect(
      el(fixture, 'modal')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(saveButton(fixture).disabled).toBe(true)

    el(fixture, 'hilos-user-cancel')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal-confirm-discard')).toBeNull()
    expect(el(fixture, 'modal')).toBeNull()
  })
})

describe('HilosUserPage lifecycle', () => {
  it('keeps own-account controls disabled with reasons and follows live rights', async () => {
    const { context } = userContext()
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()
    const find = (id: string) => el(fixture, id)
    const change = async (fn: () => void) => {
      fn()
      await Promise.resolve()
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()
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
    const { context } = userContext()
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()
    const find = (id: string) => el(fixture, id)
    const change = async (fn: () => void) => {
      fn()
      await Promise.resolve()
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()
    }
    const click = async (id: string) => {
      await change(() => {
        find(id)?.click()
      })
    }
    expect(
      (find('hilos-user-deletion-open') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(find('hilos-user-deletion-reason')?.textContent?.trim()).toBe('')
    await change(() => {
      context.scopes.page()?.data.set('accountDeletionGraceDays', 30)
    })
    find('hilos-user-deletion-open')?.click()
    await settle(fixture)
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
    const { context } = userContext()
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()
    const find = (id: string) => el(fixture, id)
    const change = async (fn: () => void) => {
      fn()
      await Promise.resolve()
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()
    }
    const click = async (id: string) => {
      await change(() => {
        find(id)?.click()
      })
    }
    let resolve!: (value: ActionResult) => void
    let reject!: (reason: unknown) => void
    find('hilos-user-block-open')?.click()
    await settle(fixture)
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
    TestBed.resetTestingModule()
    const { context, standingFrame, renameElsewhere } = userContext()
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    context.scopes.page()?.data.set('accountDeletionGraceDays', 30)
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()
    const find = (id: string) => el(fixture, id)
    const change = async (fn: () => void) => {
      fn()
      await settle(fixture)
    }
    const click = async (id: string) => {
      find(id)?.click()
      await settle(fixture)
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
      standingFrame({
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
      standingFrame({
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
      standingFrame({
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
      standingFrame({
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
      renameElsewhere('Bob')
    })
    expect(find('modal')).not.toBeNull()
    await click('hilos-user-lifecycle-cancel')
    expect(find('modal')).toBeNull()
  })

  it('closes the modal if choice is invalidated during delayed step-up opening', async () => {
    TestBed.resetTestingModule()
    const { context, standingFrame } = userContext(false, 'ask')
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()
    const find = (id: string) => el(fixture, id)
    const change = async (fn: () => void) => {
      fn()
      await settle(fixture)
    }
    const click = async (id: string) => {
      find(id)?.click()
      await settle(fixture)
    }

    await click('hilos-user-block-open')
    expect(find('hilos-user-lifecycle-step-up')).not.toBeNull()

    // Standing changes while step-up prompt is open
    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'blocked', blocked: true }),
      })
    })
    expect(find('modal')).toBeNull()
  })

  it('closes the modal when user row disappears', async () => {
    TestBed.resetTestingModule()
    const { context, removeRow } = userContext()
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()
    const find = (id: string) => el(fixture, id)
    const change = async (fn: () => void) => {
      fn()
      await settle(fixture)
    }
    const click = async (id: string) => {
      find(id)?.click()
      await settle(fixture)
    }

    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()

    await change(() => {
      removeRow()
    })
    expect(find('modal')).toBeNull()
  })

  it('does not dispatch an invalidated action if clicked between render and submit', async () => {
    TestBed.resetTestingModule()
    const { context, standingFrame } = userContext()
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()
    const find = (id: string) => el(fixture, id)
    const click = async (id: string) => {
      find(id)?.click()
      await settle(fixture)
    }

    await click('hilos-user-block-open')
    expect(find('modal')).not.toBeNull()

    const dispatch = vi.spyOn(context.actions, 'dispatch')
    // Invalidate without settling the effect first
    standingFrame({
      userId: 1,
      accountStanding: standing({ shown: 'blocked', blocked: true }),
    })
    await click('hilos-user-lifecycle-confirm')
    expect(dispatch).not.toHaveBeenCalled()
  })

  it('keeps modal open when standing changes while action is in flight, preserving readable refusal with disabled confirm', async () => {
    TestBed.resetTestingModule()
    const { context, standingFrame, renameElsewhere } = userContext()
    context.scopes.session.data.set('currentUser', { type: 'user', id: 99 })
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()
    const find = (id: string) => el(fixture, id)
    const change = async (fn: () => void) => {
      fn()
      await settle(fixture)
    }
    const click = async (id: string) => {
      find(id)?.click()
      await settle(fixture)
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
    // Standing changes while in flight
    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'blocked', blocked: true }),
      })
    })
    // Busy=true kept the modal open
    expect(find('modal')).not.toBeNull()

    // Reject with refusal
    await change(() => {
      reject(
        new ActionError(
          'hilos_user_block_set',
          'fail',
          'Operation refused by server',
        ),
      )
    })

    // Modal stays open, showing refusal
    expect(find('modal')).not.toBeNull()
    expect(find('hilos-user-lifecycle-error')?.textContent).toContain(
      'Operation refused by server',
    )
    expect(
      (find('hilos-user-lifecycle-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)

    // Extra tick/render does not dismiss the refusal
    await change(() => {
      renameElsewhere('Bob')
    })
    expect(find('modal')).not.toBeNull()
    expect(find('hilos-user-lifecycle-error')?.textContent).toContain(
      'Operation refused by server',
    )
    expect(
      (find('hilos-user-lifecycle-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)

    // Cancel closes the refusal modal
    await click('hilos-user-lifecycle-cancel')
    expect(find('modal')).toBeNull()
  })
})

describe('HilosUserPage confirmation step (HIL-1275)', () => {
  /** Mount the card with the fixture's step-up answer. */
  function mountCard(
    accountMerge: boolean,
    stepUp: 'skip' | 'ask' | 'refused',
    options: {
      detailHasPassword?: boolean
      detailHasSecondFactor?: boolean
      candidateHasSecondFactor?: boolean
      detailUnverifiedPasswordAddress?: string | null
      candidateHasPassword?: boolean
      candidateUnverifiedPasswordAddress?: string | null
    } = {},
  ): {
    fixture: ComponentFixture<HilosUserPage>
    world: ReturnType<typeof userContext>
  } {
    TestBed.resetTestingModule()
    const world = userContext(accountMerge, stepUp, options)
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', world.context)
    fixture.detectChanges()

    return { fixture, world }
  }

  async function click(
    fixture: ComponentFixture<unknown>,
    id: string,
  ): Promise<void> {
    el(fixture, id)?.click()
    await settle(fixture)
  }

  function typePassword(
    fixture: ComponentFixture<unknown>,
    text: string,
  ): void {
    const input = el(fixture, 'step-up-password') as HTMLInputElement
    input.value = text
    input.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()
  }

  it('asks by the merge operation and shows no other account before the step is passed', async () => {
    const { fixture, world } = mountCard(true, 'ask')
    const register = vi.spyOn(world.context.connection, 'registerTableWindow')
    const candidatesAsked = () =>
      register.mock.calls.some(([tableKey]) => tableKey === 'mergeCandidates')
    await click(fixture, 'hilos-user-merge-open')

    expect(world.sent[0]).toMatchObject({
      action: 'hilos_step_up_start',
      data: { operation: 'merge_accounts' },
    })
    expect(el(fixture, 'modal')?.textContent).toContain("Confirm it's you")
    expect(el(fixture, 'step-up-password')).not.toBeNull()
    expect(el(fixture, 'hilos-user-merge-next')).toBeNull()
    expect(candidatesAsked()).toBe(false)

    typePassword(fixture, 'secret')
    await click(fixture, 'hilos-user-merge-step-up-confirm')

    expect(
      world.sent.find((entry) => entry.action === 'hilos_step_up_confirm'),
    ).toMatchObject({
      data: { operation: 'merge_accounts', password: 'secret' },
    })
    expect(el(fixture, 'step-up-password')).toBeNull()
    expect(el(fixture, 'hilos-user-merge-next')).not.toBeNull()
    expect(candidatesAsked()).toBe(true)
  })

  it('opens the merge on its first step when no confirmation is needed', async () => {
    const { fixture, world } = mountCard(true, 'skip')
    await click(fixture, 'hilos-user-merge-open')

    expect(world.sent[0]?.action).toBe('hilos_step_up_start')
    expect(el(fixture, 'step-up')).toBeNull()
    expect(el(fixture, 'hilos-user-merge-next')).not.toBeNull()
    // A candidate's name and id stay apart in its row: "Bob #2", not "Bob#2".
    expect(
      el(fixture, 'hilos-user-merge-row-2')?.closest('tr')?.textContent,
    ).toContain('Bob #2')
  })

  it('draws a refusal with Cancel alone', async () => {
    const { fixture } = mountCard(true, 'refused')
    await click(fixture, 'hilos-user-merge-open')

    expect(el(fixture, 'step-up-error')?.textContent).toContain(STEP_UP_REFUSAL)
    expect(el(fixture, 'hilos-user-merge-cancel')).not.toBeNull()
    expect(el(fixture, 'hilos-user-merge-step-up-confirm')).toBeNull()
    expect(el(fixture, 'hilos-user-merge-next')).toBeNull()
  })

  it('asks before granting rights, then shows the confirmation text; Enter in the field confirms', async () => {
    const { fixture, world } = mountCard(false, 'ask')
    await click(fixture, 'hilos-user-admin-open')

    expect(world.sent[0]).toMatchObject({
      action: 'hilos_step_up_start',
      data: { operation: 'grant_admin' },
    })
    expect(el(fixture, 'hilos-user-lifecycle-step-up-confirm')).not.toBeNull()
    expect(el(fixture, 'hilos-user-lifecycle-confirm')).toBeNull()

    typePassword(fixture, 'secret')
    el(fixture, 'hilos-user-lifecycle-step-up')?.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    )
    await settle(fixture)

    expect(
      world.sent.find((entry) => entry.action === 'hilos_step_up_confirm'),
    ).toMatchObject({ data: { operation: 'grant_admin' } })
    expect(el(fixture, 'step-up')).toBeNull()
    expect(el(fixture, 'hilos-user-lifecycle-confirm')).not.toBeNull()
    expect(el(fixture, 'modal')?.textContent).not.toContain("Confirm it's you")
  })

  it('opens Lift the block without asking the server', async () => {
    const { fixture, world } = mountCard(false, 'ask')
    world.context.scopes
      .page()
      ?.entities.upsert({ type: 'user', id: 1 }, { block: true })
    await settle(fixture)
    await click(fixture, 'hilos-user-block-open')

    expect(world.sent).toEqual([])
    expect(el(fixture, 'step-up')).toBeNull()
    expect(el(fixture, 'hilos-user-lifecycle-confirm')).not.toBeNull()
  })

  it('shows removal notices for unconfirmed password addresses in the merge window (HIL-1276)', async () => {
    // 1. Password on both accounts, candidate unconfirmed
    const c1 = mountCard(true, 'skip', {
      detailHasPassword: true,
      candidateHasPassword: true,
      candidateUnverifiedPasswordAddress: 'bob@example.test',
    })
    await click(c1.fixture, 'hilos-user-merge-open')
    await click(c1.fixture, 'hilos-user-merge-row-2')
    await click(c1.fixture, 'hilos-user-merge-next')

    const survivorRemoves = el(
      c1.fixture,
      'hilos-user-merge-fate-survivor-removes',
    )
    expect(survivorRemoves?.textContent).toContain(
      'bob@example.test is not confirmed and will be removed, not moved.',
    )
    const noneRemoves = el(c1.fixture, 'hilos-user-merge-fate-none-removes')
    expect(noneRemoves?.textContent).toContain(
      'bob@example.test is not confirmed and will be removed, not moved.',
    )
    expect(el(c1.fixture, 'hilos-user-merge-fate-loser-removes')).toBeNull()
    const survivorRadio = document.getElementById(
      'hilos-user-merge-fate-survivor-field',
    )
    expect(survivorRadio?.getAttribute('aria-describedby')).toBe(
      'hilos-user-merge-fate-survivor-removes',
    )
    c1.fixture.destroy()

    // 2. Password on both accounts, survivor unconfirmed
    const c2 = mountCard(true, 'skip', {
      detailHasPassword: true,
      detailUnverifiedPasswordAddress: 'alice@example.test',
      candidateHasPassword: true,
    })
    await click(c2.fixture, 'hilos-user-merge-open')
    await click(c2.fixture, 'hilos-user-merge-row-2')
    await click(c2.fixture, 'hilos-user-merge-next')

    expect(el(c2.fixture, 'hilos-user-merge-fate-survivor-removes')).toBeNull()
    const loserRemoves = el(c2.fixture, 'hilos-user-merge-fate-loser-removes')
    expect(loserRemoves?.textContent).toContain(
      'alice@example.test is not confirmed and will be removed.',
    )
    c2.fixture.destroy()

    // 3. Both passwords confirmed
    const c3 = mountCard(true, 'skip', {
      detailHasPassword: true,
      candidateHasPassword: true,
    })
    await click(c3.fixture, 'hilos-user-merge-open')
    await click(c3.fixture, 'hilos-user-merge-row-2')
    await click(c3.fixture, 'hilos-user-merge-next')

    expect(el(c3.fixture, 'hilos-user-merge-fate-survivor-removes')).toBeNull()
    expect(el(c3.fixture, 'hilos-user-merge-fate-loser-removes')).toBeNull()
    expect(el(c3.fixture, 'hilos-user-merge-fate-none-removes')).toBeNull()
    c3.fixture.destroy()

    // 4. Password only on one account
    const c4 = mountCard(true, 'skip', {
      detailHasPassword: true,
      candidateHasPassword: false,
    })
    await click(c4.fixture, 'hilos-user-merge-open')
    await click(c4.fixture, 'hilos-user-merge-row-2')
    await click(c4.fixture, 'hilos-user-merge-next')

    expect(el(c4.fixture, 'hilos-user-merge-fate-survivor')).toBeNull()
    expect(el(c4.fixture, 'hilos-user-merge-fate-survivor-removes')).toBeNull()
    c4.fixture.destroy()
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
    hilosToasts.clear()
    document.body.classList.remove('modal-open')
  })

  /** Mount the card with the fixture's settings and step-up answer. */
  function mountCard(
    stepUp: 'skip' | 'ask' | 'refused',
    options: Parameters<typeof userContext>[2] = {},
  ): {
    fixture: ComponentFixture<HilosUserPage>
    world: ReturnType<typeof userContext>
  } {
    TestBed.resetTestingModule()
    const world = userContext(false, stepUp, options)
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', world.context)
    fixture.detectChanges()

    return { fixture, world }
  }

  async function click(
    fixture: ComponentFixture<unknown>,
    id: string,
  ): Promise<void> {
    el(fixture, id)?.click()
    await settle(fixture)
  }

  it('draws no section while the card carries no settings or impersonation is off', () => {
    expect(
      el(mountCard('skip').fixture, 'hilos-user-impersonate-open'),
    ).toBeNull()
    expect(
      el(
        mountCard('skip', {
          impersonation: impersonationSettings({ allowed: false }),
        }).fixture,
        'hilos-user-impersonate-open',
      ),
    ).toBeNull()
  })

  it('says what the takeover will be by the settings', () => {
    const { fixture } = mountCard('skip', {
      impersonation: impersonationSettings(),
    })
    const open = el(fixture, 'hilos-user-impersonate-open') as HTMLButtonElement
    const section = open.closest('section')

    expect(section?.querySelector('h2')?.textContent).toBe('Impersonation')
    expect(section?.textContent).toContain('Act as Alice')
    expect(section?.textContent).toContain(
      'You will see the product through their eyes, without your admin rights',
    )
    expect(open.textContent?.trim()).toBe('Impersonate')
    expect(open.disabled).toBe(false)
    expect(el(fixture, 'hilos-user-impersonate-reason')?.textContent).toBe('')

    const viewOnly = mountCard('skip', {
      impersonation: impersonationSettings({ scope: 'view' }),
    }).fixture
    expect(
      el(viewOnly, 'hilos-user-impersonate-open')?.closest('section')
        ?.textContent,
    ).toContain('You will only look: nothing can be changed')
  })

  it('switches the button off with its reason on the own card and on an excluded administrator', () => {
    const { fixture, world } = mountCard('skip', {
      impersonation: impersonationSettings({ equal: false }),
      currentUserId: 1,
    })
    const open = () =>
      el(fixture, 'hilos-user-impersonate-open') as HTMLButtonElement

    expect(open().disabled).toBe(true)
    expect(open().getAttribute('aria-describedby')).toBe(
      'hilos-user-impersonate-reason',
    )
    expect(el(fixture, 'hilos-user-impersonate-reason')?.textContent).toBe(
      'You cannot impersonate yourself',
    )

    world.context.scopes.session.data.set('currentUser', {
      type: 'user',
      id: 99,
    })
    world.context.scopes
      .page()
      ?.entities.upsert({ type: 'user', id: 1 }, { admin: true })
    fixture.detectChanges()

    expect(open().disabled).toBe(true)
    expect(el(fixture, 'hilos-user-impersonate-reason')?.textContent).toBe(
      'Impersonating another administrator is switched off',
    )
  })

  it('opens the window after the server says no step is needed, and closes it on the takeover', async () => {
    const { fixture, world } = mountCard('skip', {
      impersonation: impersonationSettings(),
    })
    await click(fixture, 'hilos-user-impersonate-open')

    expect(world.sent[0]).toMatchObject({
      action: 'hilos_step_up_start',
      data: { operation: 'impersonate' },
    })
    const modal = el(fixture, 'modal')
    expect(modal?.textContent).toContain('Impersonate · Alice')
    expect(modal?.textContent).toContain(
      'You will see the product through their eyes, without your admin rights.',
    )
    expect(modal?.textContent).toContain(
      'Everything you do in their name is written to the journal as done by you.',
    )

    await click(fixture, 'hilos-user-impersonate-confirm')
    expect(
      world.sent.find((entry) => entry.action === 'hilos_impersonate_start'),
    ).toMatchObject({ data: { targetUserId: 1 } })
    expect(
      el(fixture, 'hilos-user-impersonate-confirm')?.getAttribute(
        'aria-disabled',
      ),
    ).toBe('true')

    world.answerImpersonate()
    await settle(fixture)

    expect(el(fixture, 'modal')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('keeps a refusal in the window and toasts it', async () => {
    const { fixture, world } = mountCard('skip', {
      impersonation: impersonationSettings(),
    })
    await click(fixture, 'hilos-user-impersonate-open')
    await click(fixture, 'hilos-user-impersonate-confirm')

    world.answerImpersonate('Impersonating a blocked person is switched off')
    await settle(fixture)

    expect(el(fixture, 'modal')).not.toBeNull()
    expect(el(fixture, 'hilos-user-impersonate-error')?.textContent).toContain(
      'Impersonating a blocked person is switched off',
    )
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['error', 'Impersonating a blocked person is switched off']])

    await click(fixture, 'hilos-user-impersonate-cancel')
    expect(el(fixture, 'modal')).toBeNull()
  })

  it('asks for the confirmation step first when the operation is on', async () => {
    const { fixture, world } = mountCard('ask', {
      impersonation: impersonationSettings(),
    })
    await click(fixture, 'hilos-user-impersonate-open')

    expect(el(fixture, 'hilos-user-impersonate-step-up')).not.toBeNull()
    expect(el(fixture, 'hilos-user-impersonate-confirm')).toBeNull()

    const input = el(fixture, 'step-up-password') as HTMLInputElement
    input.value = 'secret'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()
    await click(fixture, 'hilos-user-impersonate-step-up-confirm')

    expect(
      world.sent.find((entry) => entry.action === 'hilos_step_up_confirm'),
    ).toMatchObject({ data: { operation: 'impersonate', password: 'secret' } })
    expect(el(fixture, 'hilos-user-impersonate-step-up')).toBeNull()
    expect(el(fixture, 'hilos-user-impersonate-confirm')).not.toBeNull()
  })

  it('updates an open confirmation and closes it when impersonation becomes unavailable', async () => {
    const { fixture, world } = mountCard('skip', {
      impersonation: impersonationSettings(),
    })
    await click(fixture, 'hilos-user-impersonate-open')
    expect(el(fixture, 'modal')).not.toBeNull()

    // Changing allowed to false via live session policy closes the window and hides the section.
    world.context.scopes.session.data.set('impersonationPolicy', {
      viewOnly: false,
      carryAdmin: false,
      allowed: false,
      accountAccess: false,
      blocked: true,
      frozen: true,
      equal: true,
    })
    await settle(fixture)

    expect(el(fixture, 'modal')).toBeNull()
    expect(el(fixture, 'hilos-user-impersonate-open')).toBeNull()

    // Bringing allowed back to true restores the section.
    world.context.scopes.session.data.set('impersonationPolicy', {
      viewOnly: false,
      carryAdmin: false,
      allowed: true,
      accountAccess: false,
      blocked: true,
      frozen: true,
      equal: true,
    })
    await settle(fixture)
    expect(el(fixture, 'hilos-user-impersonate-open')).not.toBeNull()

    // Open the window again; updating the policy to equal=false when target user is an admin closes the window.
    await click(fixture, 'hilos-user-impersonate-open')
    expect(el(fixture, 'modal')).not.toBeNull()

    world.context.scopes.session.data.set('impersonationPolicy', {
      viewOnly: true,
      carryAdmin: false,
      allowed: true,
      accountAccess: false,
      blocked: true,
      frozen: true,
      equal: true,
    })
    await settle(fixture)
    expect(el(fixture, 'modal')?.textContent).toContain(
      'You will only look: nothing can be changed',
    )

    world.context.scopes
      .page()
      ?.entities.upsert({ type: 'user', id: 1 }, { admin: true })
    world.context.scopes.session.data.set('impersonationPolicy', {
      viewOnly: false,
      carryAdmin: false,
      allowed: true,
      accountAccess: false,
      blocked: true,
      frozen: true,
      equal: false,
    })
    await settle(fixture)

    expect(el(fixture, 'modal')).toBeNull()
    const open = el(fixture, 'hilos-user-impersonate-open') as HTMLButtonElement
    expect(open.disabled).toBe(true)
  })
})

describe('HilosUserPage standing (HIL-945)', () => {
  /**
   * Mount the card on a context.
   *
   * @param context The project context the card reads.
   * @returns The fixture and a helper that applies a change and settles the view.
   */
  function mountCard(context: HilosUsersContext): {
    fixture: ComponentFixture<HilosUserPage>
    change: (fn: () => void) => Promise<void>
  } {
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()

    return {
      fixture,
      async change(fn: () => void): Promise<void> {
        fn()
        await Promise.resolve()
        fixture.detectChanges()
        await fixture.whenStable()
        fixture.detectChanges()
      },
    }
  }

  it('shows one badge for the standing shown and the freeze as a row without a control', async () => {
    const { context, standingFrame } = userContext()
    const { fixture, change } = mountCard(context)
    const find = (id: string) => el(fixture, id)
    const badge = () => find('user-standing-badge')

    // No verdict yet: no badge and no freeze row.
    expect(badge()).toBeNull()
    expect(find('hilos-user-frozen-state')).toBeNull()

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
    expect(find('hilos-user-frozen-state')?.textContent).toContain('Yes')
    expect(find('hilos-user-frozen-lapsed')?.textContent).toContain(
      'Terms of use — deadline passed 1 September 2026',
    )
    expect(find('hilos-user-frozen-open')).toBeNull()
    const states = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
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
      standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'blocked', blocked: true }),
      })
    })
    expect(badge()?.textContent?.trim()).toBe('Blocked')
    expect(badge()?.classList.contains('text-bg-danger')).toBe(true)
    expect(find('hilos-user-block-state')?.textContent).toContain('Yes')
    expect(find('hilos-user-block-open')?.textContent).toContain(
      'Lift the block',
    )
    expect(find('hilos-user-frozen-state')?.textContent).toContain('Not frozen')

    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({
          lapsed: [{ document: 'privacy', deadline: '2026-09-15' }],
        }),
      })
    })
    expect(badge()).toBeNull()
    expect(find('hilos-user-frozen-state')?.textContent).toContain(
      'Past deadline — reminders only',
    )
    expect(find('hilos-user-frozen-lapsed')?.textContent).toContain(
      'Privacy policy — deadline passed 15 September 2026',
    )

    // Hidden from a viewer of the admin view mode: no standing to show.
    await change(() => {
      standingFrame({
        userId: { _hidden: true },
        accountStanding: { _hidden: true },
      })
    })
    expect(badge()).toBeNull()
    expect(find('hilos-user-frozen-state')).not.toBeNull()
    await change(() => {
      standingFrame({ userId: 1, accountStanding: { _hidden: true } })
    })
    expect(find('hilos-user-frozen-state')).toBeNull()
  })

  it('reads a scheduled deletion from the verdict', async () => {
    const { context } = userContext()
    const { fixture, change } = mountCard(context)
    const find = (id: string) => el(fixture, id)
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

    expect(find('user-standing-badge')?.textContent?.trim()).toBe(
      'Deletion scheduled',
    )
    expect(
      find('user-standing-badge')?.classList.contains('text-bg-warning'),
    ).toBe(true)
    expect(find('hilos-user-deletion-state')?.textContent).toContain(
      '5 days left',
    )
    expect(find('hilos-user-deletion-open')?.textContent).toContain(
      'Cancel deletion',
    )
  })
})

describe('HilosUserPage in the admin view mode', () => {
  const sessionReleases: (() => void)[] = []

  afterEach(() => {
    for (const release of sessionReleases.splice(0)) {
      release()
    }
    document.body.classList.remove('modal-open')
  })

  /**
   * Bind the session scope and the admin access the way bootHilos does, over
   * handshakes this harness emits.
   *
   * @param scopes The card's scope stores.
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
    sessionReleases.push(bindAdminAccess(scopes))

    return {
      /**
       * One handshake: who is behind the session, if anybody, and the node's
       * admin view mode.
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

  /**
   * Mount the card on a context.
   *
   * @param context The project context the card reads.
   */
  function mountViewModePage(
    context: HilosUsersContext,
  ): ComponentFixture<HilosUserPage> {
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()

    return fixture
  }

  async function click(
    fixture: ComponentFixture<unknown>,
    id: string,
  ): Promise<void> {
    el(fixture, id)?.click()
    await settle(fixture)
  }

  it('a guest opens admin, block and deletion lifecycle dialogs directly and has nothing to send', async () => {
    const world = userContext()
    world.context.scopes
      .page()
      ?.data.set('accountDeletionGraceDays', HIDDEN_VALUE)
    bindSession(world.context.scopes).handshake(null, true)
    const fixture = mountViewModePage(world.context)

    for (const key of ['admin', 'block', 'deletion'] as const) {
      const openBtn = el(fixture, `hilos-user-${key}-open`) as HTMLButtonElement
      expect(openBtn).not.toBeNull()
      expect(openBtn.disabled).toBe(false)

      await click(fixture, `hilos-user-${key}-open`)

      expect(el(fixture, 'hilos-user-lifecycle-step-up')).toBeNull()
      const confirmBtn = el(
        fixture,
        'hilos-user-lifecycle-confirm',
      ) as HTMLButtonElement
      expect(confirmBtn).not.toBeNull()
      expect(confirmBtn.disabled).toBe(true)
      expect(confirmBtn.getAttribute('aria-describedby')).toContain(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )

      await click(fixture, 'hilos-user-lifecycle-confirm')
      expect(world.sent).toHaveLength(0)

      await click(fixture, 'hilos-user-lifecycle-cancel')
    }
  })

  it('a guest opens the merge dialog directly, chooses a candidate with hidden fields, and confirm is disabled', async () => {
    const world = userContext(true, 'skip', {
      candidateNameHidden: true,
      candidateIdentitiesHidden: true,
    })
    bindSession(world.context.scopes).handshake(null, true)
    const fixture = mountViewModePage(world.context)

    const openBtn = el(fixture, 'hilos-user-merge-open') as HTMLButtonElement
    expect(openBtn).not.toBeNull()
    expect(openBtn.disabled).toBe(false)

    await click(fixture, 'hilos-user-merge-open')

    expect(el(fixture, 'hilos-user-merge-step-up')).toBeNull()
    expect(world.sent).toHaveLength(0)

    const rowRadio = el(fixture, 'hilos-user-merge-row-2') as HTMLInputElement
    expect(rowRadio).not.toBeNull()
    expect(rowRadio.getAttribute('aria-label')).toBe('Merge #2')
    const row2 = rowRadio.closest('tr')
    expect(row2?.querySelectorAll('[data-id="hilos-hidden"]')).toHaveLength(2)
    // The mark and the id stay apart: "Hidden #2", never "Hidden#2".
    expect(row2?.textContent).toContain('Hidden #2')

    await click(fixture, 'hilos-user-merge-row-2')

    const nextBtn = el(fixture, 'hilos-user-merge-next') as HTMLButtonElement
    expect(nextBtn).not.toBeNull()
    await click(fixture, 'hilos-user-merge-next')

    const summary = el(fixture, 'hilos-user-merge-summary')
    expect(summary?.textContent).toContain('Hidden')

    const confirmBtn = el(
      fixture,
      'hilos-user-merge-confirm',
    ) as HTMLButtonElement
    expect(confirmBtn).not.toBeNull()
    expect(confirmBtn.disabled).toBe(true)
    expect(confirmBtn.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    await click(fixture, 'hilos-user-merge-confirm')
    expect(world.sent).toHaveLength(0)

    await click(fixture, 'hilos-user-merge-cancel')
    expect(el(fixture, 'modal-confirm-discard')).not.toBeNull()
  })

  it('a guest opens the takeover window directly and its confirm is disabled by the mode (HIL-1170)', async () => {
    const world = userContext(false, 'skip', {
      impersonation: impersonationSettings(),
    })
    bindSession(world.context.scopes).handshake(null, true)
    const fixture = mountViewModePage(world.context)

    const openBtn = el(
      fixture,
      'hilos-user-impersonate-open',
    ) as HTMLButtonElement
    expect(openBtn.disabled).toBe(false)
    await click(fixture, 'hilos-user-impersonate-open')

    expect(el(fixture, 'hilos-user-impersonate-step-up')).toBeNull()
    const confirmBtn = el(
      fixture,
      'hilos-user-impersonate-confirm',
    ) as HTMLButtonElement
    expect(confirmBtn.disabled).toBe(true)
    expect(confirmBtn.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    await click(fixture, 'hilos-user-impersonate-confirm')
    expect(world.sent).toHaveLength(0)
  })

  it('a logged-in non-admin on their own card sees block disabled by ownBlockReason without strip text id', () => {
    const world = userContext(false, 'skip', { currentUserId: 1 })
    bindSession(world.context.scopes).handshake({ id: 1, admin: false }, true)
    const fixture = mountViewModePage(world.context)

    const blockBtn = el(fixture, 'hilos-user-block-open') as HTMLButtonElement
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
    const world = userContext(false, 'skip')
    bindSession(world.context.scopes).handshake({ id: 99, admin: true }, true)
    const fixture = mountViewModePage(world.context)

    const adminBtn = el(fixture, 'hilos-user-admin-open') as HTMLButtonElement
    expect(adminBtn).not.toBeNull()
    expect(adminBtn.disabled).toBe(false)

    await click(fixture, 'hilos-user-admin-open')

    expect(world.sent[0]?.action).toBe('hilos_step_up_start')
  })

  it.each([false, true])(
    'bounds the live deletion countdown from fallback and standing (viewer=%s)',
    async (viewer) => {
      vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
      const requestedAt = 1_800_000_000_000
      const day = 86_400_000
      const effectiveAt = requestedAt + 30 * day
      vi.setSystemTime(requestedAt)
      applyServerTime(requestedAt - 300)
      const world = userContext()
      const scopes = world.context.scopes
      const { handshake } = bindSession(scopes)
      handshake(viewer ? null : { id: 99, admin: true }, true)
      const page = scopes.page()!
      page.data.set('accountDeletionGraceDays', viewer ? HIDDEN_VALUE : 7)
      page.tables.upsert('userDetail', 1, {
        users: { type: 'user', id: 1 },
        accountDeletions: {
          deletionEffectiveAt: effectiveAt,
          deletionGraceDays: 30,
        },
      })
      const fixture = mountViewModePage(world.context)
      const text = () => el(fixture, 'hilos-user-deletion-state')?.textContent
      try {
        expect(text()).toContain(formatCalendarDate(effectiveAt))
        expect(text()).toContain('30 days left')
        expect(scopes.pageDataSignal('accountDeletionGraceDays').get()).toBe(
          viewer ? HIDDEN_VALUE : 7,
        )

        await settle(fixture)
        page.data.set(
          'accountStanding',
          standing({
            shown: 'deletion_scheduled',
            deletionEffectiveAt: effectiveAt,
            deletionGraceDays: 30,
          }),
        )
        await settle(fixture)
        expect(text()).toContain('30 days left')

        vi.setSystemTime(requestedAt + day)
        vi.advanceTimersByTime(60_000)
        await settle(fixture)
        expect(text()).toContain('29 days left')

        const rescheduledAt = Date.now() + 5 * day
        world.standingFrame({
          userId: 1,
          accountStanding: standing({
            shown: 'deletion_scheduled',
            deletionEffectiveAt: rescheduledAt,
            deletionGraceDays: 5,
          }),
        })
        await settle(fixture)
        expect(text()).toContain(formatCalendarDate(rescheduledAt))
        expect(text()).toContain('5 days left')

        if (viewer) {
          await click(fixture, 'hilos-user-deletion-open')
          const confirm = el(
            fixture,
            'hilos-user-lifecycle-confirm',
          ) as HTMLButtonElement
          expect(confirm.disabled).toBe(true)
          expect(confirm.getAttribute('aria-describedby')).toContain(
            HILOS_VIEW_MODE_STRIP_TEXT_ID,
          )
          await click(fixture, 'hilos-user-lifecycle-confirm')
          expect(world.sent).toEqual([])
        }
      } finally {
        fixture.destroy()
        applyServerTime(Date.now())
        vi.useRealTimers()
      }
    },
  )
})

describe('HilosUserPage merged account (HIL-1292)', () => {
  afterEach(() => {
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
      deletionGraceDays: null,
      lapsed: [],
      window: [],
      mergedInto: 9,
      mergedIntoName: 'Bob',
      ...facts,
    }
  }

  /**
   * Mount the card on a context.
   *
   * @param context The project context the card reads.
   * @returns The fixture and a helper that applies a change and settles the view.
   */
  function mountCard(context: HilosUsersContext): {
    fixture: ComponentFixture<HilosUserPage>
    change: (fn: () => void) => Promise<void>
  } {
    TestBed.resetTestingModule()
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()

    return {
      fixture,
      async change(fn: () => void): Promise<void> {
        fn()
        await Promise.resolve()
        fixture.detectChanges()
        await fixture.whenStable()
        fixture.detectChanges()
      },
    }
  }

  it('says where the account went and offers nothing to press', async () => {
    const { context, standingFrame } = userContext(true, 'skip', {
      impersonation: impersonationSettings(),
    })
    const { fixture, change } = mountCard(context)
    const find = (id: string) => el(fixture, id)
    expect(find('hilos-user-edit')).not.toBeNull()
    expect(find('hilos-user-access')).not.toBeNull()
    expect(find('hilos-user-merge-zone')).not.toBeNull()
    expect(find('hilos-user-impersonate-open')).not.toBeNull()

    // The merge lands under the open card: the badge turns, the notice names the
    // survivor, and every control goes - the server refuses them all.
    await change(() => {
      standingFrame({ userId: 1, accountStanding: merged() })
    })
    const badge = find('user-standing-badge')
    expect(badge?.textContent?.trim()).toBe('Merged')
    expect(badge?.classList.contains('text-bg-secondary')).toBe(true)
    expect(badge?.querySelector('.bi-sign-merge-left')).not.toBeNull()
    const notice = find('hilos-user-merged')
    expect(notice?.textContent).toContain('This account was merged into')
    expect(notice?.textContent).toContain('Bob')
    expect(notice?.textContent).toContain('(#9)')
    const link = find('hilos-user-merged-link')
    expect(link?.getAttribute('href')).toBe('/hilos/user/9')
    expect(link?.textContent).toBe('Open card')
    expect(find('hilos-user-edit')).toBeNull()
    expect(find('hilos-user-rights')).toBeNull()
    expect(find('hilos-user-access')).toBeNull()
    expect(find('hilos-user-merge-zone')).toBeNull()
    expect(find('hilos-user-impersonate-open')).toBeNull()
    expect(find('hilos-user-sessions')).not.toBeNull()

    // A viewer of the admin view mode keeps the number and the way, not the name.
    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: merged({ mergedIntoName: { _hidden: true } }),
      })
    })
    expect(
      find('hilos-user-merged')?.querySelector('[data-id="hilos-hidden"]'),
    ).not.toBeNull()
    expect(find('hilos-user-merged')?.textContent).toContain('(#9)')
    expect(find('hilos-user-merged-link')).not.toBeNull()

    // A chain that leads nowhere: merged, with nobody to point at.
    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: merged({ mergedInto: null, mergedIntoName: null }),
      })
    })
    expect(find('hilos-user-merged')?.textContent?.trim()).toBe(
      'This account was merged into another one.',
    )
    expect(find('hilos-user-merged-link')).toBeNull()
  })

  it('closes an open window when the merge lands', async () => {
    const { context, standingFrame } = userContext()
    const fixture = openModal(context)
    expect(el(fixture, 'modal')).not.toBeNull()

    standingFrame({ userId: 1, accountStanding: merged() })
    await settle(fixture)

    expect(el(fixture, 'modal')).toBeNull()
    expect(el(fixture, 'hilos-user-merged')).not.toBeNull()
  })
})

describe('HilosUserPage erasing account (HIL-1962)', () => {
  afterEach(() => {
    document.body.classList.remove('modal-open')
  })

  function merged(
    facts: Record<string, unknown> = {},
  ): Record<string, unknown> {
    return {
      shown: 'merged',
      blocked: true,
      frozen: false,
      deletionEffectiveAt: null,
      deletionGraceDays: null,
      lapsed: [],
      window: [],
      mergedInto: 9,
      mergedIntoName: 'Bob',
      ...facts,
    }
  }

  function mountCard(context: HilosUsersContext): {
    fixture: ComponentFixture<HilosUserPage>
    change: (fn: () => void) => Promise<void>
  } {
    TestBed.resetTestingModule()
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()

    return {
      fixture,
      async change(fn: () => void): Promise<void> {
        fn()
        await Promise.resolve()
        fixture.detectChanges()
        await fixture.whenStable()
        fixture.detectChanges()
      },
    }
  }

  function lines(fixture: ComponentFixture<unknown>, id: string): string[] {
    return Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        `[data-id="${id}"]`,
      ),
    ).map((line) => (line.textContent ?? '').replace(/\s+/g, ' ').trim())
  }

  it('shows the erasure in progress and offers nothing to press', async () => {
    const timeZone = bindTestTimeZone('UTC')
    try {
      timeZone.receive('UTC')
      const { context, standingFrame } = userContext(true, 'skip', {
        impersonation: impersonationSettings(),
      })
      const { fixture, change } = mountCard(context)
      const find = (id: string) => el(fixture, id)

      expect(find('hilos-user-edit')).not.toBeNull()
      expect(find('hilos-user-merge-zone')).not.toBeNull()
      expect(find('hilos-user-erasure')).toBeNull()

      const startedAt = 1000
      await change(() => {
        standingFrame({
          userId: 1,
          accountStanding: standing({ shown: 'erasing' }),
          accountErasure: {
            startedAt,
            parts: [
              { participant: 'profile', owner: '1', userId: 1, done: true },
              { participant: 'chat', owner: '1', userId: 1, done: false },
              { participant: 'chat', owner: '2', userId: 2, done: false },
            ],
            waits: [{ participant: 'delivery:email', userId: 2 }],
            refusals: [],
          },
        })
      })

      const badge = find('user-standing-badge')
      expect(badge?.textContent?.trim()).toBe('Being deleted')
      expect(badge?.querySelector('i')?.getAttribute('aria-hidden')).toBe(
        'true',
      )

      const notice = find('hilos-user-erasing')
      expect(notice).not.toBeNull()
      expect(notice?.getAttribute('role')).toBe('status')
      expect(notice?.textContent).toContain('This account is being deleted.')
      expect(find('hilos-user-sessions')).not.toBeNull()

      expect(find('hilos-user-edit')).toBeNull()
      expect(find('hilos-user-rights')).toBeNull()
      expect(find('hilos-user-access')).toBeNull()
      expect(find('hilos-user-merge-zone')).toBeNull()
      expect(find('hilos-user-impersonate-open')).toBeNull()

      const section = find('hilos-user-erasure')
      expect(section).not.toBeNull()
      expect(section?.querySelector('h2')?.textContent).toBe('Account erasure')
      expect(section?.textContent).toContain('Erasure in progress')
      expect(
        Array.from(section?.querySelectorAll('h3') ?? []).map((h) =>
          h.textContent?.trim(),
        ),
      ).toEqual(['Parts', 'Waiting for an owner'])
      expect(section?.querySelector('button')).toBeNull()
      expect(find('hilos-user-erasure-started')?.textContent?.trim()).toBe(
        `Started ${String(formatMoment(startedAt, { kind: 'dateTime' }))} — it cannot be canceled`,
      )
      expect(lines(fixture, 'hilos-user-erasure-part')).toEqual([
        'profile — Done',
        'chat — Expected',
        'chat · #2 — Expected',
      ])
      expect(lines(fixture, 'hilos-user-erasure-wait')).toEqual([
        'delivery:email · #2',
      ])
      expect(lines(fixture, 'hilos-user-erasure-refusal')).toEqual([])

      // Switch reader zone: recalculated in new zone without a new frame
      await change(() => {
        timeZone.receive('America/New_York')
      })
      expect(find('hilos-user-erasure-started')?.textContent?.trim()).toBe(
        `Started ${String(formatMoment(startedAt, { kind: 'dateTime' }))} — it cannot be canceled`,
      )
    } finally {
      timeZone.release()
    }
  })

  it('says which table refused the final and whose, keeps a merged account merged', async () => {
    const { context, standingFrame } = userContext(true)
    const { fixture, change } = mountCard(context)
    const find = (id: string) => el(fixture, id)

    const erasureBlock = {
      startedAt: 1000,
      parts: [{ participant: 'chat', owner: '1', userId: 1, done: false }],
      waits: [],
      refusals: [
        { userId: 1, table: 'chat_message' },
        { userId: 7, table: null },
      ],
    }

    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'erasing' }),
        accountErasure: erasureBlock,
      })
    })

    expect(lines(fixture, 'hilos-user-erasure-refusal')).toEqual([
      'Table chat_message still references the account',
      '#7 · Another table still references the account',
    ])

    // Frame merged() with the same block: hilos-user-merged present, hilos-user-erasing absent, section present
    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: merged(),
        accountErasure: erasureBlock,
      })
    })

    expect(find('hilos-user-merged')).not.toBeNull()
    expect(find('hilos-user-erasing')).toBeNull()
    expect(find('hilos-user-erasure')).not.toBeNull()

    // 'erasing' with accountErasure: null -> badge and notice present, section absent
    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'erasing' }),
        accountErasure: null,
      })
    })

    expect(find('user-standing-badge')?.textContent?.trim()).toBe(
      'Being deleted',
    )
    expect(find('hilos-user-erasing')).not.toBeNull()
    expect(find('hilos-user-erasure')).toBeNull()
  })

  it('closes an idle window when the erasure begins and keeps a cancel in flight with its refusal (HIL-1959)', async () => {
    TestBed.resetTestingModule()
    const opened = userContext()
    const openedFixture = openModal(opened.context)
    expect(el(openedFixture, 'modal')).not.toBeNull()

    opened.standingFrame({
      userId: 1,
      accountStanding: standing({ shown: 'erasing' }),
    })
    await settle(openedFixture)
    expect(el(openedFixture, 'modal')).toBeNull()
    expect(el(openedFixture, 'hilos-user-erasing')).not.toBeNull()

    TestBed.resetTestingModule()
    const { context, standingFrame } = userContext(true, 'skip')
    const { fixture, change } = mountCard(context)
    const find = (id: string) => el(fixture, id)
    const click = async (id: string) => {
      find(id)?.click()
      await settle(fixture)
    }

    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({
          shown: 'deletion_scheduled',
          deletionEffectiveAt: 100_000_000,
        }),
      })
    })
    expect(find('hilos-user-deletion-open')).not.toBeNull()

    let reject!: (reason: unknown) => void
    vi.spyOn(context.actions, 'dispatch').mockImplementation(() => ({
      requestId: 'erasing-req',
      loading: createSignal(false),
      done: new Promise<ActionResult>((_, no) => {
        reject = no
      }),
    }))

    await click('hilos-user-deletion-open')
    expect(find('modal')).not.toBeNull()

    await click('hilos-user-lifecycle-confirm')

    await change(() => {
      standingFrame({
        userId: 1,
        accountStanding: standing({ shown: 'erasing' }),
      })
    })
    expect(find('modal')).not.toBeNull()

    await change(() => {
      reject(
        new ActionError(
          'hilos_user_deletion_set',
          'fail',
          'This account is being deleted.',
        ),
      )
    })
    expect(find('modal')).not.toBeNull()
    expect(find('hilos-user-lifecycle-error')?.textContent).toContain(
      'This account is being deleted.',
    )
  })
})

describe('HilosUserPage link to the person in Analytics (HIL-1420)', () => {
  afterEach(() => {
    document.body.classList.remove('modal-open')
  })

  /**
   * Mount the card over a router that knows the framework addresses.
   *
   * @param context The card's project context.
   * @param overrides Router members the test replaces.
   * @returns A lookup by data-id and a helper that applies a change and
   * settles the view.
   */
  function mountCard(
    context: HilosUsersContext,
    overrides: Partial<HilosRouter> = {},
  ): {
    byId: (id: string) => HTMLElement | null
    change: (fn: () => void) => Promise<void>
  } {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: HILOS_ROUTER,
          useValue: router({ resolvePath: resolveHilosPath, ...overrides }),
        },
      ],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()

    return {
      byId: (id) => el(fixture, id),
      async change(fn: () => void): Promise<void> {
        fn()
        await Promise.resolve()
        fixture.detectChanges()
        await fixture.whenStable()
        fixture.detectChanges()
      },
    }
  }

  it('draws no link before the card says Analytics is served or while it is off', async () => {
    const { context } = userContext(true)
    const { byId, change } = mountCard(context)
    expect(byId('hilos-user-detail')).not.toBeNull()
    expect(byId('hilos-user-analytics-link')).toBeNull()

    await change(() => {
      context.scopes.page()?.data.set('analyticsEnabled', false)
    })
    expect(byId('hilos-user-analytics-link')).toBeNull()
  })

  it('opens the person in Analytics from beside the card header', async () => {
    const { context } = userContext(true)
    context.scopes.page()?.data.set('analyticsEnabled', true)
    const navigate = vi.fn()
    const { byId, change } = mountCard(context, { navigate })

    const link = byId('hilos-user-analytics-link')
    expect(link?.tagName).toBe('A')
    expect(link?.textContent?.trim()).toBe('Actions in Analytics')
    expect(link?.getAttribute('href')).toBe('/hilos/analytics/person/1')
    expect(link?.closest('.card-header')).not.toBeNull()
    await change(() => link?.click())
    expect(navigate).toHaveBeenCalledWith('/hilos/analytics/person/1')
    expect(byId('modal')).toBeNull()

    await change(() => {
      context.scopes.page()?.data.set('analyticsEnabled', false)
    })
    expect(byId('hilos-user-analytics-link')).toBeNull()
  })

  it('draws no link when the application has no address for the page', () => {
    const { context } = userContext(true)
    context.scopes.page()?.data.set('analyticsEnabled', true)
    const { byId } = mountCard(context, { resolvePath: () => undefined })
    expect(byId('hilos-user-detail')).not.toBeNull()
    expect(byId('hilos-user-analytics-link')).toBeNull()
  })

  it('keeps the link on a merged card that offers nothing to press', async () => {
    const { context, standingFrame } = userContext(true, 'skip', {
      impersonation: impersonationSettings(),
    })
    context.scopes.page()?.data.set('analyticsEnabled', true)
    const { byId, change } = mountCard(context)
    await change(() => {
      standingFrame({
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
    TestBed.resetTestingModule()
  })

  /**
   * The header's presence: the room the twin holds, and the badge drawn over it.
   *
   * @param fixture The mounted card.
   */
  function presence(fixture: ComponentFixture<unknown>): {
    room: HTMLElement | null
    twin: HTMLElement | null
    badge: HTMLElement | null
  } {
    const room = (
      fixture.nativeElement as HTMLElement
    ).querySelector<HTMLElement>(
      '[data-id="hilos-user-detail"] .card-header .hilos-stack.d-inline-grid',
    )

    return {
      room,
      twin: room?.querySelector<HTMLElement>('.badge.invisible') ?? null,
      badge: room?.querySelector<HTMLElement>('.badge:not(.invisible)') ?? null,
    }
  }

  it('says the list word in the header from the first answer through every live change', async () => {
    const { context, standingFrame } = userContext()
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()
    const change = async (fn: () => void): Promise<void> => {
      fn()
      await Promise.resolve()
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()
    }
    const connections = (value: string, onlineSessionCount: number) => {
      context.scopes.page()?.tables.upsert('userDetail', 1, {
        users: { type: 'user', id: 1 },
        connections: { presence: value, onlineSessionCount },
      })
    }
    const sessions = () =>
      el(fixture, 'hilos-user-sessions')?.textContent?.trim()
    const text = () => (fixture.nativeElement as HTMLElement).textContent ?? ''

    expect(presence(fixture).badge?.textContent?.trim()).toBe('online')
    expect(presence(fixture).badge?.classList.contains('text-bg-success')).toBe(
      true,
    )
    expect(presence(fixture).twin?.textContent?.trim()).toBe('probably offline')
    expect(presence(fixture).twin?.getAttribute('aria-hidden')).toBe('true')
    // The twin is the only other badge in the card, and it is not seen.
    expect(
      Array.from(
        (fixture.nativeElement as HTMLElement).querySelectorAll(
          '[data-id="hilos-user-detail"] .badge',
        ),
      ).filter((badge) => !badge.classList.contains('invisible')),
    ).toEqual([presence(fixture).badge])

    await change(() => connections('probably_offline', 1))
    expect(presence(fixture).badge?.textContent?.trim()).toBe(
      'probably offline',
    )
    expect(presence(fixture).badge?.classList.contains('text-bg-warning')).toBe(
      true,
    )
    expect(
      presence(fixture).badge?.classList.contains('text-bg-secondary'),
    ).toBe(false)
    expect(sessions()).toBe('1')
    expect(text()).not.toContain('probably_offline')

    // The standing badge stands beside it and does not change it.
    await change(() => {
      context.scopes
        .page()
        ?.data.set(
          'accountStanding',
          standing({ shown: 'blocked', blocked: true }),
        )
    })
    expect(el(fixture, 'user-standing-badge')?.textContent?.trim()).toBe(
      'Blocked',
    )
    expect(presence(fixture).badge?.textContent?.trim()).toBe(
      'probably offline',
    )
    await change(() => {
      standingFrame({ userId: 1, accountStanding: standing({}) })
    })

    await change(() => connections('online', 2))
    expect(presence(fixture).badge?.textContent?.trim()).toBe('online')
    expect(presence(fixture).badge?.classList.contains('text-bg-success')).toBe(
      true,
    )
    expect(sessions()).toBe('2')

    await change(() => connections('offline', 0))
    expect(presence(fixture).badge?.textContent?.trim()).toBe('offline')
    expect(
      presence(fixture).badge?.classList.contains('text-bg-secondary'),
    ).toBe(true)
    expect(presence(fixture).twin?.textContent?.trim()).toBe('probably offline')
    expect(sessions()).toBe('0')
  })
})

describe('HilosUserPage read-only time zone', () => {
  afterEach(() => TestBed.resetTestingModule())

  function mountCard(
    options: {
      detailTimeZone?: string | null
      detailTimeZoneHidden?: boolean
    } = {},
  ): ComponentFixture<HilosUserPage> {
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput(
      'context',
      userContext(false, 'skip', options).context,
    )
    fixture.detectChanges()

    return fixture
  }

  it("shows the person's own zone without an edit control", () => {
    const fixture = mountCard({ detailTimeZone: 'Europe/Warsaw' })
    const row = el(fixture, 'hilos-user-time-zone')

    expect(row?.textContent?.trim()).toBe('Europe/Warsaw')
    expect(row?.querySelector('button, input, select, textarea, a')).toBeNull()
  })

  it('shows Automatic only when the visible own zone is null', () => {
    const fixture = mountCard({ detailTimeZone: null })
    const row = el(fixture, 'hilos-user-time-zone')

    expect(row?.textContent?.trim()).toBe('Automatic — follows the browser')
    expect(row?.querySelector('[data-id="hilos-hidden"]')).toBeNull()
  })

  it('keeps a hidden own zone hidden instead of showing Automatic', () => {
    const fixture = mountCard({ detailTimeZoneHidden: true })
    const row = el(fixture, 'hilos-user-time-zone')

    expect(row?.querySelector('[data-id="hilos-hidden"]')?.textContent).toBe(
      'Hidden',
    )
    expect(row?.textContent).not.toContain('Automatic — follows the browser')
  })
})

describe('HilosUserPage merge block (HIL-2011)', () => {
  const reservedReason = 'The source account is reserved'
  const sessionReleases: (() => void)[] = []

  beforeEach(() => {
    const scopes = new ScopeManager()
    scopes.session.data.set('currentUser', { type: USER_ENTITY_TYPE, id: 99 })
    scopes.session.entities.upsert(
      { type: USER_ENTITY_TYPE, id: 99 },
      { id: 99, admin: true },
    )
    sessionReleases.push(bindAdminAccess(scopes))
  })

  afterEach(() => {
    for (const release of sessionReleases.splice(0)) {
      release()
    }
    document.body.classList.remove('modal-open')
    TestBed.resetTestingModule()
  })

  function mergeWire(
    facts: Record<string, unknown> = {},
  ): Record<string, unknown> {
    return {
      operationId: 40,
      survivorUserId: 1,
      loserUserId: 9,
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
          userId: 1,
          done: false,
        },
      ],
      waits: [{ participant: 'prepare_loser', userId: 9 }],
      ...facts,
    }
  }

  function mountCard(
    context: HilosUsersContext,
  ): ComponentFixture<HilosUserPage> {
    TestBed.resetTestingModule()
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()
    return fixture
  }

  function lines(fixture: ComponentFixture<unknown>, id: string): string[] {
    return Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        `[data-id="${id}"]`,
      ),
    ).map((node) => (node.textContent ?? '').replace(/\s+/g, ' ').trim())
  }

  it('shows the initial answer with heading, status, unfinished part and wait', async () => {
    const { context, standingFrame } = userContext(true)
    context.scopes.page()!.data.set(HILOS_USER_MERGE_SECTION, mergeWire())
    const fixture = mountCard(context)
    await settle(fixture)

    expect(el(fixture, 'hilos-user-merge')).not.toBeNull()
    expect(
      el(fixture, 'hilos-user-merge')?.querySelector('h2')?.textContent,
    ).toBe('Account merge')
    expect(el(fixture, 'hilos-user-merge-status')?.textContent?.trim()).toBe(
      'Preparing merge',
    )
    expect(lines(fixture, 'hilos-user-merge-part')).toEqual([
      'Prepare receiving account',
    ])
    expect(lines(fixture, 'hilos-user-merge-wait')).toEqual([
      'Prepare source account · #9',
    ])
    expect(el(fixture, 'hilos-user-merge-view')).not.toBeNull()

    // Live frame with refusal: server words in the refusal line
    standingFrame({
      userId: 1,
      accountMerge: mergeWire({
        state: 'refusal',
        refusal: { code: 'occupied', reason: reservedReason },
      }),
    })
    await settle(fixture)
    expect(el(fixture, 'hilos-user-merge-status')?.textContent?.trim()).toBe(
      'Merge refused',
    )
    expect(el(fixture, 'hilos-user-merge-refusal')?.textContent).toContain(
      reservedReason,
    )

    // Completed end
    standingFrame({
      userId: 1,
      accountMerge: mergeWire({
        state: 'end',
        outcome: 'completed',
        parts: [],
        waits: [],
      }),
    })
    await settle(fixture)
    expect(el(fixture, 'hilos-user-merge-status')?.textContent?.trim()).toBe(
      'Merge completed',
    )
    expect(el(fixture, 'hilos-user-merge-refusal')).toBeNull()
    expect(el(fixture, 'hilos-user-merge-refusal-idle')).not.toBeNull()

    // Null: text and past refusal are gone
    standingFrame({
      userId: 1,
      accountMerge: null,
    })
    await settle(fixture)
    expect(el(fixture, 'hilos-user-merge-status')?.textContent?.trim()).toBe('')
    expect(el(fixture, 'hilos-user-merge-refusal')).toBeNull()
    expect(el(fixture, 'hilos-user-merge-view')).toBeNull()
  })

  it('shows the block and View merge on a merged account and when accountMerge is false', async () => {
    // Merged account (offersActions is false)
    const { context: mergedContext, standingFrame: standingMerged } =
      userContext(true)
    mergedContext.scopes.page()!.data.set(HILOS_USER_MERGE_SECTION, mergeWire())
    mergedContext.scopes.page()!.data.set(
      'accountStanding',
      standing({
        shown: 'merged',
        blocked: true,
        mergedInto: 9,
        mergedIntoName: 'Bob',
      }),
    )
    const mergedFixture = mountCard(mergedContext)
    await settle(mergedFixture)
    standingMerged({
      userId: 1,
      accountStanding: standing({
        shown: 'merged',
        blocked: true,
        mergedInto: 9,
        mergedIntoName: 'Bob',
      }),
      accountMerge: mergeWire(),
    })
    await settle(mergedFixture)

    expect(el(mergedFixture, 'hilos-user-merged')).not.toBeNull()
    expect(el(mergedFixture, 'hilos-user-merge')).not.toBeNull()
    expect(el(mergedFixture, 'hilos-user-merge-view')).not.toBeNull()
    expect(el(mergedFixture, 'hilos-user-merge-open')).toBeNull()

    // Context accountMerge = false
    const { context: noMergeContext } = userContext(false)
    noMergeContext.scopes
      .page()!
      .data.set(HILOS_USER_MERGE_SECTION, mergeWire())
    const noMergeFixture = mountCard(noMergeContext)
    await settle(noMergeFixture)

    expect(el(noMergeFixture, 'hilos-user-merge')).not.toBeNull()
    expect(el(noMergeFixture, 'hilos-user-merge-view')).not.toBeNull()
    expect(el(noMergeFixture, 'hilos-user-merge-open')).toBeNull()
  })
})

function mergeProgress(
  overrides: Partial<HilosAccountMergeProgress> = {},
): HilosAccountMergeProgress {
  return {
    operationId: 41,
    survivorUserId: 1,
    loserUserId: 2,
    state: 'preparing',
    stage: 'preparing',
    outcome: null,
    given: false,
    received: false,
    choice: {
      passwordFate: 'loser',
      secondFactorFate: 'both',
      expectedSurvivorHasSecondFactor: false,
      expectedLoserHasSecondFactor: true,
    },
    refusal: null,
    parts: [{ participant: 'give', owner: 'user', userId: 2, done: false }],
    waits: [{ participant: 'sessions', userId: 2 }],
    ...overrides,
  }
}

function hiddenMergeProgress(): Record<string, unknown> {
  return {
    ...mergeProgress({ state: 'end' }),
    operationId: HIDDEN_VALUE,
    survivorUserId: HIDDEN_VALUE,
    loserUserId: HIDDEN_VALUE,
    stage: HIDDEN_VALUE,
    outcome: HIDDEN_VALUE,
    choice: HIDDEN_VALUE,
    refusal: HIDDEN_VALUE,
    parts: [
      { participant: 'give', owner: 'user', userId: HIDDEN_VALUE, done: false },
    ],
    waits: [{ participant: 'sessions', userId: HIDDEN_VALUE }],
  }
}

function mountCard(
  context: HilosUsersContext,
): ComponentFixture<HilosUserPage> {
  TestBed.resetTestingModule()
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosUserPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  return fixture
}

describe('HilosUserPage merge observation (HIL-2011)', () => {
  const sessionReleases: (() => void)[] = []

  beforeEach(() => {
    const scopes = new ScopeManager()
    scopes.session.data.set('currentUser', { type: USER_ENTITY_TYPE, id: 99 })
    scopes.session.entities.upsert(
      { type: USER_ENTITY_TYPE, id: 99 },
      { id: 99, admin: true },
    )
    sessionReleases.push(bindAdminAccess(scopes))
  })

  afterEach(() => {
    for (const release of sessionReleases.splice(0)) {
      release()
    }
    document.body.classList.remove('modal-open')
    TestBed.resetTestingModule()
  })

  it('opens View merge into read-only observation with live status, and handles changed/unavailable', async () => {
    const { context, standingFrame, sent } = userContext(true)
    context.scopes.page()!.data.set(HILOS_USER_MERGE_SECTION, mergeProgress())
    const fixture = mountCard(context)
    await settle(fixture)

    const viewBtn = el(fixture, 'hilos-user-merge-view')
    expect(viewBtn).not.toBeNull()
    viewBtn?.click()
    await settle(fixture)

    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
    expect(dialog).not.toBeNull()
    expect(dialog.querySelector('.modal-title')?.textContent?.trim()).toBe(
      'Account merge',
    )

    // Pair and read-only choices
    expect(dialog.textContent).toContain('Survivor')
    expect(dialog.textContent).toContain('#1')
    expect(dialog.textContent).toContain('Other account')
    expect(dialog.textContent).toContain('#2')
    expect(dialog.textContent).toContain('Second-factor protection')
    expect(dialog.textContent).toContain('The other account password')

    // No actions dispatched, no step-up, no candidate selection window
    expect(
      sent.filter(
        (entry) =>
          entry.action.startsWith('hilos_user_merge') ||
          entry.action.startsWith('hilos_step_up_'),
      ),
    ).toEqual([])
    expect(el(fixture, 'hilos-user-merge-step-up')).toBeNull()
    expect(dialog.querySelector('input[type="radio"]')).toBeNull()
    expect(dialog.querySelector('input')).toBeNull()

    // Live frames update status in the open window
    standingFrame({
      userId: 1,
      accountMerge: mergeProgress({ state: 'wait', stage: 'go' }),
    })
    await settle(fixture)
    expect(dialog.textContent).toContain('Merge in progress')

    standingFrame({
      userId: 1,
      accountMerge: mergeProgress({
        state: 'refusal',
        refusal: {
          code: 'occupied',
          reason: 'The other account is reserved elsewhere.',
        },
      }),
    })
    await settle(fixture)
    expect(dialog.textContent).toContain('Merge refused')
    expect(
      el(fixture, 'hilos-user-merge-operation-refusal')?.textContent,
    ).toContain('The other account is reserved elsewhere.')

    // Null -> unavailable
    standingFrame({
      userId: 1,
      accountMerge: null,
    })
    await settle(fixture)
    expect(dialog.textContent).toContain('Merge progress is unavailable.')

    // Another operation -> changed
    standingFrame({
      userId: 1,
      accountMerge: mergeProgress({ operationId: 99 }),
    })
    await settle(fixture)
    expect(dialog.textContent).toContain(
      'The merge operation has changed. Close this window and open it again.',
    )

    // Close and Escape send nothing, repeated View merge shows live state
    const sentCountBeforeClose = sent.length
    el(fixture, 'hilos-user-merge-close')?.click()
    await settle(fixture)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(sent.length).toBe(sentCountBeforeClose)

    // Reopen View merge
    standingFrame({
      userId: 1,
      accountMerge: mergeProgress({ state: 'wait', stage: 'go' }),
    })
    await settle(fixture)
    el(fixture, 'hilos-user-merge-view')?.click()
    await settle(fixture)
    const reopenedDialog =
      document.querySelector<HTMLElement>('[role="dialog"]')!
    expect(reopenedDialog).not.toBeNull()
    expect(reopenedDialog.textContent).toContain('Merge in progress')

    // Escape closes without sending
    reopenedDialog.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await settle(fixture)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(sent.length).toBe(sentCountBeforeClose)
  })

  it('keeps observation open when merge lands, but spectator mode offers no Merge or Cancel', async () => {
    // Merged account under open observation: window stays
    const { context, standingFrame } = userContext(true)
    context.scopes.page()!.data.set(HILOS_USER_MERGE_SECTION, mergeProgress())
    const fixture = mountCard(context)
    await settle(fixture)

    el(fixture, 'hilos-user-merge-view')?.click()
    await settle(fixture)
    expect(document.querySelector('[role="dialog"]')).not.toBeNull()

    // Merge lands: standing changes to merged
    standingFrame({
      userId: 1,
      accountStanding: standing({
        shown: 'merged',
        blocked: true,
        mergedInto: 9,
        mergedIntoName: 'Bob',
      }),
      accountMerge: mergeProgress(),
    })
    await settle(fixture)
    expect(document.querySelector('[role="dialog"]')).not.toBeNull()
    expect(el(fixture, 'hilos-user-merged')).not.toBeNull()
    expect(el(fixture, 'hilos-user-merge-open')).toBeNull()

    // Close dialog
    el(fixture, 'hilos-user-merge-close')?.click()
    await settle(fixture)
    expect(document.querySelector('[role="dialog"]')).toBeNull()

    // Spectator mode: bind session with adminViewMode
    const spectatorWorld = userContext(true)
    spectatorWorld.context.scopes.session.data.set('adminViewMode', true)
    spectatorWorld.context.scopes.session.data.set('currentUser', {
      type: USER_ENTITY_TYPE,
      id: 99,
    })
    spectatorWorld.context.scopes.session.entities.upsert(
      { type: USER_ENTITY_TYPE, id: 99 },
      { id: 99, admin: false },
    )
    sessionReleases.push(bindAdminAccess(spectatorWorld.context.scopes))
    spectatorWorld.context.scopes
      .page()!
      .data.set(HILOS_USER_MERGE_SECTION, hiddenMergeProgress())

    const spectatorFixture = mountCard(spectatorWorld.context)
    await settle(spectatorFixture)

    // Open observation
    el(spectatorFixture, 'hilos-user-merge-view')?.click()
    await settle(spectatorFixture)
    const spectatorDialog =
      document.querySelector<HTMLElement>('[role="dialog"]')!
    expect(spectatorDialog).not.toBeNull()
    expect(
      spectatorDialog.querySelector('[data-id="hilos-user-merge-confirm"]'),
    ).toBeNull()
    expect(
      spectatorDialog.querySelector(
        '[data-id="hilos-user-merge-operation-cancel"]',
      ),
    ).toBeNull()
  })
})

describe('HilosUserPage merge start (HIL-2011)', () => {
  const sessionReleases: (() => void)[] = []

  beforeEach(() => {
    const scopes = new ScopeManager()
    scopes.session.data.set('currentUser', { type: USER_ENTITY_TYPE, id: 99 })
    scopes.session.entities.upsert(
      { type: USER_ENTITY_TYPE, id: 99 },
      { id: 99, admin: true },
    )
    sessionReleases.push(bindAdminAccess(scopes))
  })

  afterEach(() => {
    for (const release of sessionReleases.splice(0)) {
      release()
    }
    document.body.classList.remove('modal-open')
    TestBed.resetTestingModule()
  })

  it('opens observation in preparing state on accepted reply without toast, then shows progress for matching operation frame', async () => {
    hilosToasts.clear()
    const world = userContext(true)
    sessionReleases.push(bindAdminAccess(world.context.scopes))
    const fixture = await openMergeOnBob(world.context)
    mergeConfirm(fixture).click()
    await settle(fixture)
    expect(
      world.sent.filter((entry) => entry.action === 'hilos_user_merge'),
    ).toHaveLength(1)

    world.answerMerge(undefined, { operationId: 41 })
    await settle(fixture)

    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
    expect(dialog).not.toBeNull()
    expect(dialog.textContent).toContain('Preparing merge')
    expect(hilosToasts.toasts.get()).toEqual([])

    world.standingFrame({
      userId: 1,
      accountMerge: mergeProgress({
        operationId: 41,
        state: 'wait',
        stage: 'go',
      }),
    })
    await settle(fixture)
    expect(dialog.textContent).toContain('Merge in progress')
  })

  it('keeps step 2 open with server refusal words when merge is refused', async () => {
    const world = userContext(true)
    sessionReleases.push(bindAdminAccess(world.context.scopes))
    const fixture = await openMergeOnBob(world.context)
    mergeConfirm(fixture).click()
    await settle(fixture)

    world.answerMerge('The other account is reserved elsewhere.')
    await settle(fixture)

    expect(el(fixture, 'hilos-user-merge-summary')).not.toBeNull()
    expect(fixture.nativeElement.textContent).toContain(
      'The other account is reserved elsewhere.',
    )
  })

  it('keeps step 2 on timeout with notice and no refusal error line, keeps Merge enabled, then adopts matching operation frame', async () => {
    const world = userContext(true)
    sessionReleases.push(bindAdminAccess(world.context.scopes))
    let reject!: (reason: unknown) => void
    const fixture = await openMergeOnBob(world.context)
    vi.spyOn(world.context.actions, 'dispatch').mockImplementation(() => ({
      requestId: 'test-req',
      loading: createSignal(false),
      done: new Promise<ActionResult>((_, no) => {
        reject = no
      }),
    }))
    mergeConfirm(fixture).click()
    await settle(fixture)

    reject(
      new ActionError('hilos_user_merge', 'timeout', 'Operation timed out'),
    )
    await settle(fixture)

    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
    expect(dialog).not.toBeNull()
    expect(el(fixture, 'hilos-user-merge-summary')).not.toBeNull()
    expect(dialog.textContent).toContain(
      'The merge request got no answer. If the merge started, it appears here.',
    )
    expect(el(fixture, 'hilos-action-error')).toBeNull()
    expect(mergeConfirm(fixture).disabled).toBe(false)

    world.standingFrame({
      userId: 1,
      accountMerge: mergeProgress({
        operationId: 77,
        choice: {
          passwordFate: null,
          secondFactorFate: null,
          expectedSurvivorHasSecondFactor: false,
          expectedLoserHasSecondFactor: false,
        },
      }),
    })
    await settle(fixture)
    expect(dialog.textContent).toContain('Preparing merge')
    expect(el(fixture, 'hilos-user-merge-summary')).toBeNull()
  })

  it('disables candidate radio and locks Next when candidate is occupied', async () => {
    const world = userContext(true, 'skip', {
      candidateMergeAvailability: { reserved: true, available: false },
    })
    sessionReleases.push(bindAdminAccess(world.context.scopes))
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: router() }],
    })
    const fixture = TestBed.createComponent(HilosUserPage)
    fixture.componentRef.setInput('context', world.context)
    fixture.detectChanges()

    el(fixture, 'hilos-user-merge-open')?.click()
    await settle(fixture)

    const radio = el(fixture, 'hilos-user-merge-row-2') as HTMLInputElement
    expect(radio.disabled).toBe(true)
    radio.click()
    await settle(fixture)
    expect(radio.checked).toBe(false)
    expect(
      (el(fixture, 'hilos-user-merge-next') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('shows no longer available and disables Merge when candidate becomes unavailable on step 2', async () => {
    const world = userContext(true)
    sessionReleases.push(bindAdminAccess(world.context.scopes))
    const fixture = await openMergeOnBob(world.context)
    expect(mergeConfirm(fixture).disabled).toBe(false)

    world.updateCandidate({
      accountMergeAvailability: { reserved: true, available: false },
    })
    await settle(fixture)

    expect(el(fixture, 'hilos-user-merge-gone')?.textContent?.trim()).toBe(
      'No longer available',
    )
    expect(mergeConfirm(fixture).disabled).toBe(true)
  })

  it('resets password choice but preserves 2FA choice when unverified password address changes', async () => {
    const world = userContext(true, 'skip', {
      detailHasPassword: true,
      candidateHasPassword: true,
      detailHasSecondFactor: true,
      candidateHasSecondFactor: true,
      candidateUnverifiedPasswordAddress: 'bob@example.com',
    })
    sessionReleases.push(bindAdminAccess(world.context.scopes))
    const fixture = await openMergeOnBob(world.context)

    el(fixture, 'hilos-user-merge-fate-loser')?.click()
    el(fixture, 'hilos-user-merge-second-factor-both')?.click()
    await settle(fixture)

    const fateRadio = el(
      fixture,
      'hilos-user-merge-fate-loser',
    ) as HTMLInputElement
    const secondFactorRadio = el(
      fixture,
      'hilos-user-merge-second-factor-both',
    ) as HTMLInputElement
    expect(fateRadio.checked).toBe(true)
    expect(secondFactorRadio.checked).toBe(true)

    world.updateCandidate({
      unverifiedPasswordAddress: 'newaddress@example.com',
    })
    await settle(fixture)

    expect(fateRadio.checked).toBe(false)
    expect(secondFactorRadio.checked).toBe(true)
  })

  it('erases candidate snapshot upon loss of full admin access', async () => {
    const world = userContext(true)
    sessionReleases.push(bindAdminAccess(world.context.scopes))
    const fixture = await openMergeOnBob(world.context)
    expect(el(fixture, 'hilos-user-merge-summary')).not.toBeNull()

    world.removeCandidate()
    await settle(fixture)
    expect(el(fixture, 'hilos-user-merge-summary')).not.toBeNull()

    world.access(false)
    await settle(fixture)

    expect(el(fixture, 'hilos-user-merge-summary')).toBeNull()
  })
})

describe('HilosUserPage merge cancel (HIL-2011)', () => {
  const sessionReleases: (() => void)[] = []

  beforeEach(() => {
    const scopes = new ScopeManager()
    scopes.session.data.set('currentUser', { type: USER_ENTITY_TYPE, id: 99 })
    scopes.session.entities.upsert(
      { type: USER_ENTITY_TYPE, id: 99 },
      { id: 99, admin: true },
    )
    sessionReleases.push(bindAdminAccess(scopes))
  })

  afterEach(() => {
    for (const release of sessionReleases.splice(0)) {
      release()
    }
    document.body.classList.remove('modal-open')
    TestBed.resetTestingModule()
  })

  it.each([
    {
      name: 'preparing',
      snapshot: mergeProgress({ state: 'preparing' }),
      offered: true,
    },
    {
      name: 'wait before give',
      snapshot: mergeProgress({ state: 'wait', stage: 'go', given: false }),
      offered: true,
    },
    {
      name: 'cancelling',
      snapshot: mergeProgress({ state: 'wait', stage: 'cancelling' }),
      offered: false,
    },
    {
      name: 'given',
      snapshot: mergeProgress({ given: true }),
      offered: false,
    },
    {
      name: 'end',
      snapshot: mergeProgress({ state: 'end', outcome: 'not_started' }),
      offered: false,
    },
    {
      name: 'spectator',
      snapshot: mergeProgress({ state: 'preparing' }),
      spectator: true,
      offered: false,
    },
    {
      name: 'disabled project',
      snapshot: mergeProgress({ state: 'preparing' }),
      disabled: true,
      offered: false,
    },
  ])(
    'offers cancellation button only when allowed: $name',
    async (scenario) => {
      const world = userContext(!scenario.disabled)
      if (scenario.spectator) {
        world.scopes.session.data.set('adminViewMode', true)
        world.scopes.session.entities.upsert(
          { type: USER_ENTITY_TYPE, id: 99 },
          { id: 99, admin: false },
        )
      }
      sessionReleases.push(bindAdminAccess(world.context.scopes))
      world.scopes.page()!.data.set(HILOS_USER_MERGE_SECTION, scenario.snapshot)
      const fixture = mountCard(world.context)
      await settle(fixture)
      el(fixture, 'hilos-user-merge-view')?.click()
      await settle(fixture)

      const cancelButton = el(
        fixture,
        'hilos-user-merge-operation-cancel',
      ) as HTMLButtonElement | null
      expect(cancelButton !== null).toBe(scenario.offered)
      if (scenario.offered) {
        expect(cancelButton?.textContent?.trim()).toBe('Cancel merge')
        expect(cancelButton?.disabled).toBe(false)
      }
    },
  )

  it('tracks cancellation request with loading, hides button upon ack, updates on cancelling frame, and closes with toast on completed release', async () => {
    hilosToasts.clear()
    const world = userContext(true)
    sessionReleases.push(bindAdminAccess(world.context.scopes))
    world.scopes
      .page()!
      .data.set(
        HILOS_USER_MERGE_SECTION,
        mergeProgress({ state: 'preparing', operationId: 41 }),
      )
    const fixture = mountCard(world.context)
    await settle(fixture)
    el(fixture, 'hilos-user-merge-view')?.click()
    await settle(fixture)

    const cancelButton = el(
      fixture,
      'hilos-user-merge-operation-cancel',
    ) as HTMLButtonElement
    expect(cancelButton).not.toBeNull()
    cancelButton.click()
    await settle(fixture)

    expect(world.sent).toContainEqual({
      action: 'hilos_user_merge_cancel',
      data: { operationId: 41 },
      requestId: expect.any(String),
    })
    expect(cancelButton.getAttribute('aria-busy')).toBe('true')

    world.answerMergeCancel()
    await settle(fixture)
    expect(el(fixture, 'hilos-user-merge-operation-cancel')).toBeNull()

    world.standingFrame({
      userId: 1,
      accountMerge: mergeProgress({
        operationId: 41,
        state: 'wait',
        stage: 'cancelling',
      }),
    })
    await settle(fixture)
    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
    expect(dialog.textContent).toContain('Releasing merge reservations')
    expect(el(fixture, 'hilos-user-merge-operation-cancel')).toBeNull()

    world.standingFrame({
      userId: 1,
      accountMerge: mergeProgress({
        operationId: 41,
        state: 'end',
        stage: 'cancelling',
        outcome: 'not_started',
        refusal: null,
      }),
    })
    await settle(fixture)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(hilosToasts.toasts.get()).toMatchObject([
      { severity: 'success', message: 'Merge canceled' },
    ])
  })

  it('surfaces cancellation refusal and keeps merge progress displayed', async () => {
    const world = userContext(true)
    sessionReleases.push(bindAdminAccess(world.context.scopes))
    world.scopes
      .page()!
      .data.set(
        HILOS_USER_MERGE_SECTION,
        mergeProgress({ state: 'preparing', operationId: 41 }),
      )
    const fixture = mountCard(world.context)
    await settle(fixture)
    el(fixture, 'hilos-user-merge-view')?.click()
    await settle(fixture)

    el(fixture, 'hilos-user-merge-operation-cancel')?.click()
    await settle(fixture)

    world.answerMergeCancel('The merge can no longer be canceled')
    await settle(fixture)

    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
    expect(dialog).not.toBeNull()
    expect(dialog.textContent).toContain('Preparing merge')
    expect(
      el(fixture, 'hilos-user-merge-cancel-refusal')?.textContent,
    ).toContain('The merge can no longer be canceled')
  })

  // FIXME(F-132, 2026-11-24): leaked 30s ActionLifecycle timer from the merge test at :606 posts a toast here · foreign · attempt 1 · HIL-2290
  it.skip('restores cancellation button on timeout without toast', async () => {
    hilosToasts.clear()
    const world = userContext(true)
    sessionReleases.push(bindAdminAccess(world.context.scopes))
    world.scopes
      .page()!
      .data.set(
        HILOS_USER_MERGE_SECTION,
        mergeProgress({ state: 'preparing', operationId: 41 }),
      )
    let reject!: (reason: unknown) => void
    vi.spyOn(world.context.actions, 'dispatch').mockImplementation(() => ({
      requestId: 'test-req',
      loading: createSignal(false),
      done: new Promise<ActionResult>((_, no) => {
        reject = no
      }),
    }))

    const fixture = mountCard(world.context)
    await settle(fixture)
    el(fixture, 'hilos-user-merge-view')?.click()
    await settle(fixture)

    el(fixture, 'hilos-user-merge-operation-cancel')?.click()
    await settle(fixture)

    reject(
      new ActionError(
        'hilos_user_merge_cancel',
        'timeout',
        'Operation timed out',
      ),
    )
    await settle(fixture)

    const cancelButton = el(
      fixture,
      'hilos-user-merge-operation-cancel',
    ) as HTMLButtonElement
    expect(cancelButton).not.toBeNull()
    expect(cancelButton.disabled).toBe(false)
    expect(cancelButton.getAttribute('aria-busy')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('does not send cancellation request when closed via Close button or Escape', async () => {
    const world = userContext(true)
    sessionReleases.push(bindAdminAccess(world.context.scopes))
    world.scopes
      .page()!
      .data.set(
        HILOS_USER_MERGE_SECTION,
        mergeProgress({ state: 'preparing', operationId: 41 }),
      )
    const fixture = mountCard(world.context)
    await settle(fixture)
    el(fixture, 'hilos-user-merge-view')?.click()
    await settle(fixture)

    el(fixture, 'hilos-user-merge-close')?.click()
    await settle(fixture)
    expect(
      world.sent.filter((entry) => entry.action === 'hilos_user_merge_cancel'),
    ).toHaveLength(0)

    // Reopen and close via Escape
    el(fixture, 'hilos-user-merge-view')?.click()
    await settle(fixture)
    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
    dialog.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await settle(fixture)
    expect(
      world.sent.filter((entry) => entry.action === 'hilos_user_merge_cancel'),
    ).toHaveLength(0)
  })
})

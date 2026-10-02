// The Angular peer of vue/src/admin/users/HilosUserPage.test.ts and
// react/test/HilosUserPage.test.tsx (HIL-1050), under the same case names: the
// rename modal on the shared row-edit helper. A viewer of the admin view mode
// (HIL-1260, HIL-1263) sees the mark in place of a hidden value, opens every
// window at once, and finds the confirmation in it disabled by the mode.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  ActionError,
  type ActionResult,
  bindAdminAccess,
  bindSessionScope,
  formatCalendarDate,
  HIDDEN_VALUE,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosConnection,
  HilosPages,
  hilosToasts,
  ScopeManager,
  createSignal,
  entityCollection,
  USER_ENTITY_TYPE,
  userFromFields,
} from '@hilos/core'
import type {
  HilosRouter,
  HilosUsersContext,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosUserPage } from '../src/admin/users/HilosUserPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

function router(): HilosRouter {
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
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
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
    detailUnverifiedPasswordAddress?: string | null
    candidateHasPassword?: boolean
    candidateUnverifiedPasswordAddress?: string | null
    candidateNameHidden?: boolean
    candidateIdentitiesHidden?: boolean
    impersonation?: Record<string, unknown>
    currentUserId?: number
  } = {},
): {
  context: HilosUsersContext
  renameElsewhere: (name: string) => void
  removeRow: () => void
  failRename: () => void
  standingFrame: (data: Record<string, unknown>) => void
  answerImpersonate: (reason?: string) => void
  sent: Array<{ action: string; data: unknown; requestId?: string }>
} {
  const scopes = new ScopeManager()
  const page = scopes.openPage(HilosPages.USER)
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
  page.entities.upsert(
    { type: 'user', id: 1 },
    { id: 1, name: 'Alice', lastActivity: null },
  )
  page.tables.upsert('userDetail', 1, {
    users: { type: 'user', id: 1 },
    connections: { presence: 'online', onlineSessionCount: 1 },
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
          unverifiedPasswordAddress:
            options.candidateUnverifiedPasswordAddress ?? null,
        },
      },
    },
    {
      rowKey: 99,
      slots: {
        users: { id: 99, name: 'Admin', lastActivity: null },
        merge: { identities: [], hasPassword: false },
      },
    },
  ]
  const tableWindowListeners: Array<(signal: unknown) => void> = []
  const serveCandidates = (): void => {
    const signal = {
      kind: 'tableWindow',
      data: {
        page: HilosPages.USER,
        tableKey: 'mergeCandidates',
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
  vi.spyOn(connection, 'sendTableViewport').mockImplementation(() => {
    serveCandidates()

    return true
  })
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
    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent.at(-1)?.data).toEqual({ id: 1, name: 'Mine' })

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
    saveButton(fixture).click()
    fixture.detectChanges()

    failRename()
    fixture.detectChanges()
    expect(saveButton(fixture).disabled).toBe(false)

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
      (find('hilos-user-lifecycle-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)
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
})

describe('HilosUserPage confirmation step (HIL-1275)', () => {
  /** Mount the card with the fixture's step-up answer. */
  function mountCard(
    accountMerge: boolean,
    stepUp: 'skip' | 'ask' | 'refused',
    options: {
      detailHasPassword?: boolean
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
      (el(fixture, 'hilos-user-impersonate-confirm') as HTMLButtonElement)
        .disabled,
    ).toBe(true)

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
})

describe('HilosUserPage standing (HIL-945)', () => {
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
      lapsed: [],
      window: [],
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
})

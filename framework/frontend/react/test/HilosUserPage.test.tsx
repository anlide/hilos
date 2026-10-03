import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  ActionError,
  type ActionResult,
  formatCalendarDate,
  HIDDEN_VALUE,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosConnection,
  HilosPages,
  hilosToasts,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
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

import { HilosUserPage } from '../src/admin/users/HilosUserPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function router(): HilosRouter {
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
    detailUnverifiedPasswordAddress?: string | null
    candidateHasPassword?: boolean
    candidateUnverifiedPasswordAddress?: string | null
    candidateNameHidden?: boolean
    candidateIdentitiesHidden?: boolean
    impersonation?: Record<string, unknown>
    currentUserId?: number
  } = {},
): HilosUsersContext & {
  renameElsewhere: (name: string) => void
  removeRow: () => void
  failRename: () => void
  standingFrame: (data: Record<string, unknown>) => void
  answerImpersonate: (reason?: string) => void
  sent: Array<{ action: string; data: unknown; requestId?: string }>
} {
  const scopes = new ScopeManager()
  const page = scopes.openPage('hilos_user')
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
      { id: 1, name: 'Alice', lastActivity: '2026-06-19 10:00' },
    )
    page.tables.upsert('userDetail', 1, {
      users: { type: 'user', id: 1 },
      connections: { presence: 'online', onlineSessionCount: 2 },
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

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function nameInput(): HTMLInputElement {
  return byId('hilos-user-name-input') as HTMLInputElement
}

function saveButton(): HTMLButtonElement {
  return byId('hilos-user-save') as HTMLButtonElement
}

/** Render the seeded page and open the rename modal. */
function openModal(context: HilosUsersContext): void {
  const { container } = renderPage(context)
  fireEvent.click(
    container.querySelector('[data-id="hilos-user-edit"]') as Element,
  )
}

function renderPage(context: HilosUsersContext) {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosUserPage context={context} />
    </HilosRouterContext.Provider>,
  )
}

describe('HilosUserPage', () => {
  afterEach(() => {
    cleanup()
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
      (byId('hilos-user-impersonate-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)

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
})

describe('HilosUserPage standing (HIL-945)', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

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
